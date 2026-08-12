<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\Contact;
use App\Support\EmailAddress;
use App\Support\PhoneNumber;
use App\Support\PortalAccessToken;
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

    /** Max OTP SMS requests per phone within the decay window. */
    private const OTP_RATE_LIMIT = 15;

    private const OTP_RATE_DECAY_SECONDS = 300;

    /** Min seconds between two OTP sends for the same phone. */
    private const SEND_COOLDOWN_SECONDS = 15;

    /** Wrong verify guesses allowed per phone before the OTP is invalidated. */
    private const VERIFY_MAX_ATTEMPTS = 10;

    private const VERIFY_DECAY_SECONDS = 180;

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
        if (! PhoneNumber::isValidEthioTelecomMobile($phone)) {
            throw new RuntimeException('Unable to send verification code. Please try again.');
        }

        // New customers may sign in with OTP, then complete TIN (ERCA) → CRM confirm.
        // Do not reveal whether the phone is already registered.

        $cooldownKey = 'portal-otp:cooldown:'.$phone;
        $cooldownSeconds = AppSetting::otpSendCooldown();
        if ($cooldownSeconds > 0 && AppSetting::otpRateLimitEnabled()) {
            $cooldownExpiresAt = Cache::get($cooldownKey);
            if ($cooldownExpiresAt) {
                $remaining = max(1, (int) $cooldownExpiresAt - time());
                throw new RuntimeException("Please wait {$remaining} second(s) before requesting another code.");
            }
        }

        $this->assertOtpRateLimitNotExceeded($phone);

        $otp = $this->generateOtp();
        $this->store($phone, $otp);

        $message = 'VAS Partners portal sign-in. '
            ."Your verification code is {$otp}. It expires in ".self::EXPIRY_MINUTES.' minutes. '
            .'If you did not request this, ignore this message. Ethio telecom';

        try {
            // Sync gateway call — only report success after acceptance (not after queueing).
            $this->sms->sendOtp($phone, $message);
        } catch (RuntimeException $e) {
            $this->deleteRecord($phone);

            throw new RuntimeException('Unable to send verification code. Please try again.');
        }

        RateLimiter::hit('portal-otp:phone:'.$phone, self::OTP_RATE_DECAY_SECONDS);
        if ($cooldownSeconds > 0 && AppSetting::otpRateLimitEnabled()) {
            Cache::put($cooldownKey, time() + $cooldownSeconds, $cooldownSeconds);
        }

        Log::info('Portal login OTP sent', [
            'phone_masked' => $this->maskPhone($phone),
        ]);

        return [
            'phone' => $phone,
            'expires_in' => self::EXPIRY_MINUTES * 60,
        ];
    }

    /**
     * Verify OTP and return an authenticated contact (creates profile on first sign-in).
     *
     * @param  array{name?: ?string, email?: ?string, gender?: ?string, nationality?: ?string}|null  $profile
     * @return array{
     *   contact: Contact,
     *   token: string,
     *   is_new: bool,
     *   expires_in: int,
     *   identity: array<string, mixed>
     * }
     */
    public function verify(string $rawPhone, string $code, ?array $profile = null): array
    {
        $this->assertEnabled();

        $phone = PhoneNumber::normalize($rawPhone);
        if (! PhoneNumber::isValidEthioTelecomMobile($phone)) {
            throw new RuntimeException('Enter a valid mobile number.');
        }

        $code = trim($code);
        if (! preg_match('/^\d{6}$/', $code)) {
            throw new RuntimeException('Enter the 6-digit verification code.');
        }

        $this->assertVerifyNotLocked($phone);

        $record = $this->findValidRecord($phone, $code);
        if (! $record) {
            $this->recordFailedVerify($phone);

            throw new RuntimeException('Invalid or expired verification code.');
        }

        $this->clearAllRateLimits($phone);
        $this->deleteRecord($phone);

        $result = $this->findOrCreateContact($phone, $profile ?? []);
        $contact = $result['contact'];

        // Portal access is gated by contact is_active only (no separate ban flag).
        // Deactivated companies do not block sign-in — partners may switch or create another company.
        if (! $contact->is_active) {
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

        // Members (and owners) with active memberships always get a company context.
        $contact = $membership->ensureActiveCompanyContext($contact);

        // CRM confirm after TIN-validated company membership; members skip company create.
        $identity = app(ContactIdentityService::class)->resolveAfterAuth($contact);
        $token = PortalAccessToken::issue($contact, PortalAccessToken::NAME_OTP);

        return [
            'contact' => $contact->fresh(['company', 'memberships.company']) ?? $contact,
            'token' => $token,
            'is_new' => $result['is_new'],
            'expires_in' => PortalAccessToken::ttlMinutes() * 60,
            'identity' => $identity,
        ];
    }

    /**
     * Create or load contact after OTP. New phones get a provisional contact;
     * company TIN (ERCA) then CRM confirm complete the journey.
     *
     * @param  array{name?: ?string, email?: ?string, gender?: ?string, nationality?: ?string}  $profile
     * @return array{contact: Contact, is_new: bool}
     */
    protected function findOrCreateContact(string $phone, array $profile): array
    {
        $contact = Contact::query()->where('phone_number', $phone)->first();

        if ($contact) {
            return ['contact' => $contact, 'is_new' => false];
        }

        // Prefer CRM consent after company setup. Optional name from the form is a fallback only.
        $displayName = trim((string) ($profile['name'] ?? ''));
        if ($displayName !== '' && (mb_strlen($displayName) < 2 || mb_strlen($displayName) > 120)) {
            throw new RuntimeException('Name must be between 2 and 120 characters.');
        }
        if ($displayName === '') {
            $displayName = 'Partner';
        }

        $email = EmailAddress::normalize($profile['email'] ?? null);
        if ($email && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Please enter a valid email address.');
        }

        $gender = trim((string) ($profile['gender'] ?? ''));
        if ($gender !== '' && ! PortalProfileOptions::isValidGender($gender)) {
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
            'gender' => $gender !== '' ? $gender : null,
            'nationality' => $nationality,
            'identification_type' => '2',
            'identification_number' => $sub,
        ]);
        $contact->forceFill(['is_active' => true])->save();

        return ['contact' => $contact->fresh(), 'is_new' => true];
    }

    /** @deprecated Eligibility is open for OTP; company + CRM gates happen after verify. */
    protected function isEligiblePortalPhone(string $phone): bool
    {
        return PhoneNumber::isValidEthioTelecomMobile($phone);
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

    private function assertOtpRateLimitNotExceeded(string $phone): void
    {
        if (! AppSetting::otpRateLimitEnabled()) {
            return;
        }

        $key = 'portal-otp:phone:'.$phone;

        if (RateLimiter::tooManyAttempts($key, AppSetting::otpServiceRateLimit())) {
            $seconds = RateLimiter::availableIn($key);
            $minutes = max(1, (int) ceil($seconds / 60));

            throw new RuntimeException("Too many requests. Please try again in about {$minutes} minute(s).");
        }
    }

    private function verifyAttemptKey(string $phone): string
    {
        return 'portal-otp:verify:'.$phone;
    }

    private function assertVerifyNotLocked(string $phone): void
    {
        if (! AppSetting::otpRateLimitEnabled()) {
            return;
        }

        if (! RateLimiter::tooManyAttempts($this->verifyAttemptKey($phone), AppSetting::otpVerifyMaxAttempts())) {
            return;
        }

        $seconds = RateLimiter::availableIn($this->verifyAttemptKey($phone));
        $minutes = max(1, (int) ceil($seconds / 60));

        throw new RuntimeException(
            "Too many incorrect codes. Request a new verification code in about {$minutes} minute(s).",
        );
    }

    private function recordFailedVerify(string $phone): void
    {
        if (! AppSetting::otpRateLimitEnabled()) {
            return;
        }

        $key = $this->verifyAttemptKey($phone);
        RateLimiter::hit($key, self::VERIFY_DECAY_SECONDS);

        if (! RateLimiter::tooManyAttempts($key, AppSetting::otpVerifyMaxAttempts())) {
            return;
        }

        // Burn the OTP so remaining guesses cannot continue after lockout.
        $this->deleteRecord($phone);

        Log::warning('Portal login OTP locked after failed verifies', [
            'phone_masked' => $this->maskPhone($phone),
        ]);
    }

    private function clearVerifyAttempts(string $phone): void
    {
        RateLimiter::clear($this->verifyAttemptKey($phone));
    }

    /**
     * Release every portal-OTP rate-limit cache key for this phone so a fresh
     * session starts clean. Redis TTL also auto-expires these, but this
     * guarantees immediate release on successful auth.
     */
    private function clearAllRateLimits(string $phone): void
    {
        RateLimiter::clear('portal-otp:phone:'.$phone);
        $this->clearVerifyAttempts($phone);
        Cache::forget('portal-otp:cooldown:'.$phone);
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

        // Fresh code → allow a new verify window (still subject to request rate limits).
        $this->clearVerifyAttempts($phone);
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
