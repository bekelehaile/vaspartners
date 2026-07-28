<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\Contact;
use App\Support\EmailAddress;
use App\Support\PhoneNumber;
use App\Support\PortalProfileOptions;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * Partner (customer) portal sign-in via SMS OTP — alternative while Fayda prod is unstable.
 * Admin password-reset OTP is separate ({@see AdminPasswordOtpService}).
 */
class PortalPhoneOtpService
{
    public const PURPOSE = 'portal_login';

    private const EXPIRY_MINUTES = 5;

    private const OTP_RATE_LIMIT = 3;

    private const OTP_RATE_DECAY_SECONDS = 300;

    private const SEND_COOLDOWN_SECONDS = 60;

    public function __construct(
        private readonly SmsService $sms,
    ) {}

    public function assertEnabled(): void
    {
        if (! AppSetting::phoneOtpEnabled()) {
            throw new RuntimeException('Phone OTP sign-in is currently disabled.');
        }
    }

    /**
     * @return array{phone: string, expires_in: int}
     */
    public function request(string $rawPhone): array
    {
        $this->assertEnabled();

        $phone = PhoneNumber::normalize($rawPhone);
        if (! PhoneNumber::isValidLocalMobile($phone)) {
            throw new RuntimeException('Enter a valid Ethio telecom mobile number.');
        }

        $cooldownKey = 'portal-otp:cooldown:'.$phone;
        if (Cache::has($cooldownKey)) {
            throw new RuntimeException('An OTP was already sent. Please wait a minute before requesting another.');
        }

        $this->applyOtpRateLimit($phone);

        $otp = $this->generateOtp();
        $this->store($phone, $otp);

        $message = "VAS Partners portal sign-in.\n"
            ."Your verification code is {$otp}. It expires in ".self::EXPIRY_MINUTES." minutes.\n"
            .'If you did not request this, ignore this message. Ethio telecom';

        $this->sms->send($phone, $message);
        Cache::put($cooldownKey, true, self::SEND_COOLDOWN_SECONDS);

        Log::info('Portal login OTP sent', [
            'phone_masked' => $this->maskPhone($phone),
        ]);

        return [
            'phone' => $phone,
            'expires_in' => self::EXPIRY_MINUTES * 60,
            'needs_name' => ! Contact::query()->where('phone_number', $phone)->exists(),
        ];
    }

    /**
     * Verify OTP and return an authenticated contact (creates profile on first sign-in).
     *
     * @param  array{name?: ?string, email?: ?string, gender?: ?string, nationality?: ?string}|null  $profile
     * @return array{contact: Contact, token: string, is_new: bool}
     */
    public function verify(string $rawPhone, string $code, ?array $profile = null): array
    {
        $this->assertEnabled();

        $phone = PhoneNumber::normalize($rawPhone);
        if (! PhoneNumber::isValidLocalMobile($phone)) {
            throw new RuntimeException('Enter a valid Ethio telecom mobile number.');
        }

        $code = trim($code);
        if (! preg_match('/^\d{6}$/', $code)) {
            throw new RuntimeException('Enter the 6-digit verification code.');
        }

        $record = $this->findValidRecord($phone, $code);
        if (! $record) {
            throw new RuntimeException('Invalid or expired verification code.');
        }

        $this->deleteRecord($phone);

        $result = $this->findOrCreateContact($phone, $profile ?? []);
        $contact = $result['contact'];

        if ($contact->is_banned || ! $contact->is_active) {
            throw new RuntimeException('This account is not allowed to sign in.');
        }

        $membership = app(CompanyMembershipService::class);
        try {
            $membership->trySyncMembershipsOnFaydaLogin($contact->fresh(['memberships.company']));
        } catch (Throwable $e) {
            report($e);
        }
        try {
            $membership->tryAutoClaimMigratedCompanyByPhone($contact->fresh(['memberships.company']));
        } catch (Throwable $e) {
            report($e);
        }

        $contact = $contact->fresh(['company', 'memberships.company']);
        $token = $contact->createToken('phone_otp')->plainTextToken;

        return [
            'contact' => $contact,
            'token' => $token,
            'is_new' => $result['is_new'],
        ];
    }

    /**
     * @param  array{name?: ?string, email?: ?string, gender?: ?string, nationality?: ?string}  $profile
     * @return array{contact: Contact, is_new: bool}
     */
    protected function findOrCreateContact(string $phone, array $profile): array
    {
        $contact = Contact::query()->where('phone_number', $phone)->first();

        if ($contact) {
            return ['contact' => $contact, 'is_new' => false];
        }

        $displayName = trim((string) ($profile['name'] ?? ''));
        if ($displayName === '') {
            throw new RuntimeException('Please enter your full name to create your profile.');
        }
        if (mb_strlen($displayName) < 2 || mb_strlen($displayName) > 120) {
            throw new RuntimeException('Name must be between 2 and 120 characters.');
        }

        $email = EmailAddress::normalize($profile['email'] ?? null);
        if (! $email || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Please enter a valid email address.');
        }

        $gender = trim((string) ($profile['gender'] ?? ''));
        if (! PortalProfileOptions::isValidGender($gender)) {
            throw new RuntimeException('Please select gender (Male or Female).');
        }

        $nationality = trim((string) ($profile['nationality'] ?? PortalProfileOptions::DEFAULT_NATIONALITY));
        if ($nationality === '') {
            $nationality = PortalProfileOptions::DEFAULT_NATIONALITY;
        }
        if (! PortalProfileOptions::isValidNationality($nationality)) {
            throw new RuntimeException('Please select a valid nationality.');
        }

        $sub = 'otp-'.$phone;
        $contact = new Contact;
        $contact->syncFromFayda([
            'sub' => $sub,
            'name' => $displayName,
            'phone_number' => $phone,
            'email' => $email,
            'gender' => $gender,
            'nationality' => $nationality,
            'identification_type' => '2',
            'identification_number' => $sub,
        ]);
        $contact->forceFill(['is_active' => true])->save();

        return ['contact' => $contact->fresh(), 'is_new' => true];
    }

    protected function findValidRecord(string $phone, string $otp): ?stdClass
    {
        $record = DB::table('otps')
            ->where('phone_number', $phone)
            ->where('purpose', self::PURPOSE)
            ->where('code', $this->hash($otp))
            ->first();

        if (! $record) {
            return null;
        }

        if (Carbon::parse($record->expires_at)->isPast()) {
            $this->deleteRecord($phone);

            return null;
        }

        return $record;
    }

    protected function deleteRecord(string $phone): void
    {
        DB::table('otps')
            ->where('phone_number', $phone)
            ->where('purpose', self::PURPOSE)
            ->delete();
    }

    private function applyOtpRateLimit(string $phone): void
    {
        $key = 'portal-otp:phone:'.$phone;

        if (RateLimiter::tooManyAttempts($key, self::OTP_RATE_LIMIT)) {
            throw new RuntimeException('Too many verification codes requested. Please try again in a few minutes.');
        }

        RateLimiter::hit($key, self::OTP_RATE_DECAY_SECONDS);
    }

    private function generateOtp(int $length = 6): string
    {
        return str_pad(
            (string) random_int(0, (10 ** $length) - 1),
            $length,
            '0',
            STR_PAD_LEFT
        );
    }

    private function store(string $phone, string $otp): void
    {
        DB::transaction(function () use ($phone, $otp): void {
            DB::table('otps')
                ->where('phone_number', $phone)
                ->where('purpose', self::PURPOSE)
                ->delete();

            DB::table('otps')->insert([
                'phone_number' => $phone,
                'purpose' => self::PURPOSE,
                'code' => $this->hash($otp),
                'expires_at' => now()->addMinutes(self::EXPIRY_MINUTES),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    private function hash(string $otp): string
    {
        return hash('sha256', $otp);
    }

    private function maskPhone(string $phone): string
    {
        if (strlen($phone) <= 4) {
            return '****';
        }

        return str_repeat('*', strlen($phone) - 4).substr($phone, -4);
    }
}
