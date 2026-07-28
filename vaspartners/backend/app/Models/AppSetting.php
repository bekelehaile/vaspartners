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
}
