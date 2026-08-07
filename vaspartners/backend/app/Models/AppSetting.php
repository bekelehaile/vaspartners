<?php

namespace App\Models;

use App\Support\RevenueDuplicatePolicy;
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

    /** Partner / event SMS (tickets, company, bulk). Does not control login OTP. */
    public const KEY_NOTIFY_PARTNER_SMS = 'notify_partner_sms';

    /** Partner portal database (in-app) notifications. */
    public const KEY_NOTIFY_PARTNER_IN_APP = 'notify_partner_in_app';

    /** Partner email — reserved; delivery not wired yet. */
    public const KEY_NOTIFY_PARTNER_EMAIL = 'notify_partner_email';

    /**
     * Monthly Revenue duplicate policy (JSON). Replaces the old on/off toggle.
     *
     * @see \App\Support\RevenueDuplicatePolicy
     */
    public const KEY_REVENUE_DUPLICATE_POLICY = 'revenue_duplicate_policy';

    /** @deprecated Use KEY_REVENUE_DUPLICATE_POLICY */
    public const KEY_REVENUE_BLOCK_DUPLICATES = 'revenue_block_duplicates';

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

    /**
     * Stored as "1" / "0". Missing key = enabled (safe default for production).
     */
    public static function boolValue(string $key, bool $default = true): bool
    {
        $raw = static::getValue($key);
        if ($raw === null || $raw === '') {
            return $default;
        }

        return in_array(strtolower(trim((string) $raw)), ['1', 'true', 'yes', 'on'], true);
    }

    public static function setBoolValue(string $key, bool $value): void
    {
        static::setValue($key, $value ? '1' : '0');
    }

    /** Queued / ad-hoc partner SMS (not portal login OTP). */
    public static function partnerSmsEnabled(): bool
    {
        return static::boolValue(self::KEY_NOTIFY_PARTNER_SMS, true);
    }

    public static function partnerInAppEnabled(): bool
    {
        return static::boolValue(self::KEY_NOTIFY_PARTNER_IN_APP, true);
    }

    /** Reserved for future mail delivery. */
    public static function partnerEmailEnabled(): bool
    {
        return static::boolValue(self::KEY_NOTIFY_PARTNER_EMAIL, false);
    }

    public static function revenueDuplicatePolicy(): RevenueDuplicatePolicy
    {
        $raw = static::getValue(self::KEY_REVENUE_DUPLICATE_POLICY);
        if (filled($raw)) {
            $decoded = json_decode((string) $raw, true);
            if (is_array($decoded)) {
                return RevenueDuplicatePolicy::fromArray($decoded);
            }
        }

        // Legacy boolean: on → both + default match fields; off → scope off.
        if (static::boolValue(self::KEY_REVENUE_BLOCK_DUPLICATES, false)) {
            return RevenueDuplicatePolicy::fromArray([
                'scope' => RevenueDuplicatePolicy::SCOPE_BOTH,
                'match' => [
                    RevenueDuplicatePolicy::MATCH_SERVICE_ID,
                    RevenueDuplicatePolicy::MATCH_SHORT_CODE,
                    RevenueDuplicatePolicy::MATCH_MONTH,
                    RevenueDuplicatePolicy::MATCH_PARTNER,
                ],
                'action' => RevenueDuplicatePolicy::ACTION_BLOCK,
            ]);
        }

        return RevenueDuplicatePolicy::default();
    }

    public static function setRevenueDuplicatePolicy(RevenueDuplicatePolicy $policy): void
    {
        static::setValue(self::KEY_REVENUE_DUPLICATE_POLICY, json_encode($policy->toArray(), JSON_THROW_ON_ERROR));
        // Clear legacy flag so policy JSON is the source of truth.
        static::setBoolValue(self::KEY_REVENUE_BLOCK_DUPLICATES, false);
    }

    /**
     * @deprecated Use revenueDuplicatePolicy()->enforces()
     */
    public static function revenueBlockDuplicates(): bool
    {
        return static::revenueDuplicatePolicy()->enforces();
    }

    /**
     * @return array{sms: bool, in_app: bool, email: bool}
     */
    public static function partnerNotificationChannels(): array
    {
        return [
            'sms' => static::partnerSmsEnabled(),
            'in_app' => static::partnerInAppEnabled(),
            'email' => static::partnerEmailEnabled(),
        ];
    }
}
