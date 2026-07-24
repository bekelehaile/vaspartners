<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;
use stdClass;

/**
 * Admin forgot-password OTP (aligned with fixedservices InteractsWithSMSGateway flow).
 */
class AdminPasswordOtpService
{
    private const EXPIRY_MINUTES = 5;

    private const OTP_RATE_LIMIT = 3;

    private const OTP_RATE_DECAY_SECONDS = 300;

    /** Block a second SMS for the same phone within this window (double-submit / double-click). */
    private const SEND_COOLDOWN_SECONDS = 60;

    public function __construct(
        private readonly SmsService $sms,
    ) {}

    public function send(string|int $phone): string
    {
        $normalized = $this->sms->normalizePhone($phone);
        $cooldownKey = 'admin-otp:cooldown:'.$normalized;

        if (Cache::has($cooldownKey)) {
            throw new RuntimeException('An OTP was already sent. Please wait a minute before requesting another.');
        }

        $this->applyOtpRateLimit($normalized);

        $otp = $this->generateOtp();
        $this->store($normalized, $otp);

        $message = "VAS Partners admin password reset.\n"
            ."Your verification code is {$otp}. It expires in ".self::EXPIRY_MINUTES." minutes.\n"
            .'If you did not request this, ignore this message. Ethio telecom';

        $this->sms->send($normalized, $message);
        Cache::put($cooldownKey, true, self::SEND_COOLDOWN_SECONDS);

        Log::info('Admin password-reset OTP sent', [
            'phone_masked' => $this->maskPhone($normalized),
        ]);

        return $otp;
    }

    public function findValidRecord(string $otp): ?stdClass
    {
        $record = DB::table('otps')
            ->where('code', $this->hash($otp))
            ->first();

        if (! $record) {
            return null;
        }

        if (Carbon::parse($record->expires_at)->isPast()) {
            $this->deleteByCode($otp);

            return null;
        }

        return $record;
    }

    public function deleteByCode(string $otp): void
    {
        DB::table('otps')
            ->where('code', $this->hash($otp))
            ->delete();
    }

    private function applyOtpRateLimit(string $phone): void
    {
        $key = 'admin-otp:phone:'.$phone;

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
            DB::table('otps')->where('phone_number', $phone)->delete();

            DB::table('otps')->insert([
                'phone_number' => $phone,
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
