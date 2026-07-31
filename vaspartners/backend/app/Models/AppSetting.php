<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class AppSetting extends Model
{
    public const AUTH_MODE_FAYDA = 'fayda';

    public const AUTH_MODE_PHONE_OTP = 'phone_otp';

    public const AUTH_MODE_BOTH = 'both';

    public const KEY_AUTH_MODE = 'auth_mode';

    /** ERCA / eTrade TIN lookup is live. */
    public const ERCA_TIN_MODE_LIVE = 'live';

    /** Admins marked ERCA TIN lookup as down — partners get the outage message (fail-closed). */
    public const ERCA_TIN_MODE_MAINTENANCE = 'maintenance';

    public const KEY_ERCA_TIN_MODE = 'erca_tin_mode';

    public const KEY_ERCA_TIN_OUTAGE_MESSAGE = 'erca_tin_outage_message';

    public const DEFAULT_ERCA_TIN_OUTAGE_MESSAGE = 'TIN number verification is temporarily unavailable. Please try again shortly.';

    protected $fillable = [
        'key',
        'value',
    ];

    public static function getValue(string $key, ?string $default = null): ?string
    {
        return Cache::remember("app_setting:{$key}", 60, function () use ($key, $default) {
            $row = static::query()->where('key', $key)->first();

            return $row?->value ?? $default;
        });
    }

    public static function setValue(string $key, ?string $value): void
    {
        static::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value],
        );
        Cache::forget("app_setting:{$key}");
    }

    public static function authMode(): string
    {
        $mode = static::getValue(self::KEY_AUTH_MODE, self::AUTH_MODE_BOTH);

        return in_array($mode, [
            self::AUTH_MODE_FAYDA,
            self::AUTH_MODE_PHONE_OTP,
            self::AUTH_MODE_BOTH,
        ], true) ? $mode : self::AUTH_MODE_BOTH;
    }

    public static function faydaEnabled(): bool
    {
        return in_array(static::authMode(), [self::AUTH_MODE_FAYDA, self::AUTH_MODE_BOTH], true);
    }

    public static function phoneOtpEnabled(): bool
    {
        return in_array(static::authMode(), [self::AUTH_MODE_PHONE_OTP, self::AUTH_MODE_BOTH], true);
    }

    /**
     * @return array{auth_mode: string, fayda_enabled: bool, phone_otp_enabled: bool, note: ?string}
     */
    public static function authConfig(): array
    {
        $note = static::getValue('auth_mode_note');

        return [
            'auth_mode' => static::authMode(),
            'fayda_enabled' => static::faydaEnabled(),
            'phone_otp_enabled' => static::phoneOtpEnabled(),
            'note' => filled($note) ? $note : null,
        ];
    }

    public static function ercaTinMode(): string
    {
        $mode = static::getValue(self::KEY_ERCA_TIN_MODE, self::ERCA_TIN_MODE_LIVE);

        return in_array($mode, [
            self::ERCA_TIN_MODE_LIVE,
            self::ERCA_TIN_MODE_MAINTENANCE,
        ], true) ? $mode : self::ERCA_TIN_MODE_LIVE;
    }

    public static function ercaTinInMaintenance(): bool
    {
        return static::ercaTinMode() === self::ERCA_TIN_MODE_MAINTENANCE;
    }

    /**
     * Partner-facing message when ERCA is in maintenance or the upstream call fails.
     */
    public static function ercaTinOutageMessage(?string $fallback = null): string
    {
        $custom = static::getValue(self::KEY_ERCA_TIN_OUTAGE_MESSAGE);
        if (filled($custom)) {
            return trim((string) $custom);
        }

        return $fallback ?: self::DEFAULT_ERCA_TIN_OUTAGE_MESSAGE;
    }

    /**
     * @return array{mode: string, available: bool, message: ?string}
     */
    public static function ercaTinConfig(): array
    {
        $maintenance = static::ercaTinInMaintenance();

        return [
            'mode' => static::ercaTinMode(),
            'available' => ! $maintenance,
            'message' => $maintenance ? static::ercaTinOutageMessage() : null,
        ];
    }
}
