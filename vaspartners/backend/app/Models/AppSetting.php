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

    /**
     * AM coverage: who may work on another AM’s Monthly Revenue / partners.
     * JSON list of {delegate_id, owner_ids: int[]}.
     */
    public const KEY_REVENUE_AM_DELEGATIONS = 'revenue_am_delegations';

    public const KEY_OTP_RATE_LIMIT_ENABLED = 'otp_rate_limit_enabled';

    public const KEY_OTP_REQUEST_BURST = 'otp_request_burst';

    public const KEY_OTP_REQUEST_HOURLY = 'otp_request_hourly';

    public const KEY_OTP_VERIFY_BURST = 'otp_verify_burst';

    public const KEY_OTP_VERIFY_HOURLY = 'otp_verify_hourly';

    public const KEY_OTP_SEND_COOLDOWN = 'otp_send_cooldown';

    public const KEY_OTP_SERVICE_RATE_LIMIT = 'otp_service_rate_limit';

    public const KEY_OTP_VERIFY_MAX_ATTEMPTS = 'otp_verify_max_attempts';

    public const KEY_TIN_RATE_LIMIT_ENABLED = 'tin_rate_limit_enabled';

    public const KEY_TIN_LOOKUP_PER_MINUTE = 'tin_lookup_per_minute';

    public const KEY_TIN_LOOKUP_PER_IP_MINUTE = 'tin_lookup_per_ip_minute';

    public const KEY_TIN_LOOKUP_PER_HOUR = 'tin_lookup_per_hour';

    public const KEY_TIN_LOOKUP_PER_DAY = 'tin_lookup_per_day';

    public const KEY_TIN_UNIQUE_TINS_PER_DAY = 'tin_unique_tins_per_day';

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

    public static function otpRateLimitEnabled(): bool
    {
        return static::boolValue(self::KEY_OTP_RATE_LIMIT_ENABLED, true);
    }

    public static function otpRequestBurst(): int
    {
        return max(1, (int) static::getValue(self::KEY_OTP_REQUEST_BURST, '30'));
    }

    public static function otpRequestHourly(): int
    {
        return max(1, (int) static::getValue(self::KEY_OTP_REQUEST_HOURLY, '60'));
    }

    public static function otpVerifyBurst(): int
    {
        return max(1, (int) static::getValue(self::KEY_OTP_VERIFY_BURST, '60'));
    }

    public static function otpVerifyHourly(): int
    {
        return max(1, (int) static::getValue(self::KEY_OTP_VERIFY_HOURLY, '200'));
    }

    public static function otpSendCooldown(): int
    {
        return max(0, (int) static::getValue(self::KEY_OTP_SEND_COOLDOWN, '15'));
    }

    public static function otpServiceRateLimit(): int
    {
        return max(1, (int) static::getValue(self::KEY_OTP_SERVICE_RATE_LIMIT, '15'));
    }

    public static function otpVerifyMaxAttempts(): int
    {
        return max(1, (int) static::getValue(self::KEY_OTP_VERIFY_MAX_ATTEMPTS, '10'));
    }

    public static function tinRateLimitEnabled(): bool
    {
        return static::boolValue(self::KEY_TIN_RATE_LIMIT_ENABLED, true);
    }

    public static function tinLookupPerMinute(): int
    {
        return max(1, (int) static::getValue(self::KEY_TIN_LOOKUP_PER_MINUTE, '10'));
    }

    public static function tinLookupPerIpMinute(): int
    {
        return max(1, (int) static::getValue(self::KEY_TIN_LOOKUP_PER_IP_MINUTE, '30'));
    }

    public static function tinLookupPerHour(): int
    {
        return max(1, (int) static::getValue(self::KEY_TIN_LOOKUP_PER_HOUR, '25'));
    }

    public static function tinLookupPerDay(): int
    {
        return max(1, (int) static::getValue(self::KEY_TIN_LOOKUP_PER_DAY, '50'));
    }

    public static function tinUniqueTinsPerDay(): int
    {
        return max(1, (int) static::getValue(self::KEY_TIN_UNIQUE_TINS_PER_DAY, '20'));
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
     * @return list<array{delegate_id: int, owner_ids: list<int>}>
     */
    public static function revenueAmDelegations(): array
    {
        $raw = static::getValue(self::KEY_REVENUE_AM_DELEGATIONS);
        if (! filled($raw)) {
            return [];
        }

        $decoded = json_decode((string) $raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        $rows = [];
        foreach ($decoded as $row) {
            if (! is_array($row)) {
                continue;
            }
            $delegateId = (int) ($row['delegate_id'] ?? 0);
            if ($delegateId <= 0) {
                continue;
            }
            $ownerIds = collect(is_array($row['owner_ids'] ?? null) ? $row['owner_ids'] : [])
                ->map(fn ($id) => (int) $id)
                ->filter(fn (int $id) => $id > 0 && $id !== $delegateId)
                ->unique()
                ->values()
                ->all();
            if ($ownerIds === []) {
                continue;
            }
            $rows[] = [
                'delegate_id' => $delegateId,
                'owner_ids' => $ownerIds,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array{delegate_id?: mixed, owner_ids?: mixed}>  $rows
     */
    public static function setRevenueAmDelegations(array $rows): void
    {
        static::setValue(
            self::KEY_REVENUE_AM_DELEGATIONS,
            json_encode(static::revenueAmDelegationsFromInput($rows), JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @param  list<array{delegate_id?: mixed, owner_ids?: mixed}>  $rows
     * @return list<array{delegate_id: int, owner_ids: list<int>}>
     */
    public static function revenueAmDelegationsFromInput(array $rows): array
    {
        return collect($rows)
            ->map(function ($row): ?array {
                if (! is_array($row)) {
                    return null;
                }
                $delegateId = (int) ($row['delegate_id'] ?? 0);
                if ($delegateId <= 0) {
                    return null;
                }
                $ownerIds = collect(is_array($row['owner_ids'] ?? null) ? $row['owner_ids'] : [])
                    ->map(fn ($id) => (int) $id)
                    ->filter(fn (int $id) => $id > 0 && $id !== $delegateId)
                    ->unique()
                    ->values()
                    ->all();

                return $ownerIds === [] ? null : [
                    'delegate_id' => $delegateId,
                    'owner_ids' => $ownerIds,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Owner user IDs this actor may work for (always includes self).
     * null = unrestricted (admin / management).
     *
     * @return list<int>|null
     */
    public static function revenueOwnerIdsFor(User $actor): ?array
    {
        if ($actor->canAccessAllRevenue()) {
            return null;
        }

        $ids = [(int) $actor->id];
        foreach (static::revenueAmDelegations() as $row) {
            if ((int) $row['delegate_id'] !== (int) $actor->id) {
                continue;
            }
            foreach ($row['owner_ids'] as $ownerId) {
                $ids[] = (int) $ownerId;
            }
        }

        return array_values(array_unique($ids));
    }

    public static function canActForRevenueOwner(User $actor, ?int $ownerUserId): bool
    {
        if ($actor->canAccessAllRevenue()) {
            return true;
        }

        if (! $ownerUserId) {
            return false;
        }

        $ids = static::revenueOwnerIdsFor($actor);

        return is_array($ids) && in_array((int) $ownerUserId, $ids, true);
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
