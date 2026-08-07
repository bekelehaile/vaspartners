<?php

namespace App\Support;

/**
 * Monthly Revenue duplicate policy from App settings.
 *
 * Scope  — where to look (off / within import / prior SMS / both)
 * Match  — fields that must all match (AND) to count as a duplicate
 * Action — block (enforce) or allow surpass (config saved, not enforced)
 */
final class RevenueDuplicatePolicy
{
    public const SCOPE_OFF = 'off';

    public const SCOPE_WITHIN_IMPORT = 'within_import';

    public const SCOPE_PRIOR_SENDS = 'prior_sends';

    public const SCOPE_BOTH = 'both';

    public const ACTION_BLOCK = 'block';

    public const ACTION_ALLOW_SURPASS = 'allow_surpass';

    public const MATCH_SERVICE_ID = 'service_id';

    public const MATCH_SHORT_CODE = 'short_code';

    public const MATCH_MONTH = 'month';

    public const MATCH_AM = 'am';

    public const MATCH_CATALOG = 'catalog_service';

    public const MATCH_COMPANY = 'company';

    public const MATCH_PARTNER = 'partner';

    public const MATCH_AMOUNT = 'amount';

    /**
     * @param  list<string>  $match
     */
    public function __construct(
        public readonly string $scope,
        public readonly array $match,
        public readonly string $action,
    ) {}

    public static function default(): self
    {
        return new self(
            self::SCOPE_OFF,
            [
                self::MATCH_SERVICE_ID,
                self::MATCH_SHORT_CODE,
                self::MATCH_MONTH,
            ],
            self::ACTION_BLOCK,
        );
    }

    /**
     * @return array<string, string>
     */
    public static function scopeFlagOptions(): array
    {
        return [
            self::SCOPE_WITHIN_IMPORT => 'Within this import',
            self::SCOPE_PRIOR_SENDS => 'Against prior SMS sends',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function scopeOptions(): array
    {
        return [
            self::SCOPE_OFF => 'Off — no duplicate checks',
            self::SCOPE_WITHIN_IMPORT => 'Within this import only',
            self::SCOPE_PRIOR_SENDS => 'Against prior SMS sends',
            self::SCOPE_BOTH => 'Within import and prior SMS',
        ];
    }

    /**
     * @param  list<string>  $flags
     */
    public static function scopeFromFlags(array $flags): string
    {
        $within = in_array(self::SCOPE_WITHIN_IMPORT, $flags, true);
        $prior = in_array(self::SCOPE_PRIOR_SENDS, $flags, true);

        return match (true) {
            $within && $prior => self::SCOPE_BOTH,
            $within => self::SCOPE_WITHIN_IMPORT,
            $prior => self::SCOPE_PRIOR_SENDS,
            default => self::SCOPE_OFF,
        };
    }

    /**
     * @return list<string>
     */
    public function scopeFlags(): array
    {
        return match ($this->scope) {
            self::SCOPE_BOTH => [self::SCOPE_WITHIN_IMPORT, self::SCOPE_PRIOR_SENDS],
            self::SCOPE_WITHIN_IMPORT => [self::SCOPE_WITHIN_IMPORT],
            self::SCOPE_PRIOR_SENDS => [self::SCOPE_PRIOR_SENDS],
            default => [],
        };
    }

    /**
     * Human-readable AND rule, e.g. "Service ID AND Month AND AM".
     */
    public function matchRuleLabel(): string
    {
        $labels = self::matchOptions();

        return collect($this->match)
            ->map(fn (string $key): string => $labels[$key] ?? $key)
            ->filter()
            ->implode(' AND ');
    }

    /**
     * @return array<string, string>
     */
    public static function matchOptions(): array
    {
        return [
            self::MATCH_SERVICE_ID => 'Service ID',
            self::MATCH_SHORT_CODE => 'Short code',
            self::MATCH_MONTH => 'Month',
            self::MATCH_AM => 'Account manager (AM)',
            self::MATCH_CATALOG => 'Catalog service',
            self::MATCH_COMPANY => 'Company',
            self::MATCH_PARTNER => 'Partner',
            self::MATCH_AMOUNT => 'Amount',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function actionOptions(): array
    {
        return [
            self::ACTION_BLOCK => 'Block — mark Duplicate and do not send',
            self::ACTION_ALLOW_SURPASS => 'Allow surpass — keep config, do not enforce yet',
        ];
    }

    /**
     * @return list<string>
     */
    public static function matchKeys(): array
    {
        return array_keys(self::matchOptions());
    }

    /**
     * @param  array{scope?: mixed, match?: mixed, action?: mixed}  $data
     */
    public static function fromArray(array $data): self
    {
        $scope = (string) ($data['scope'] ?? self::SCOPE_OFF);
        if (! array_key_exists($scope, self::scopeOptions())) {
            $scope = self::SCOPE_OFF;
        }

        $action = (string) ($data['action'] ?? self::ACTION_BLOCK);
        if (! array_key_exists($action, self::actionOptions())) {
            $action = self::ACTION_BLOCK;
        }

        $allowed = self::matchKeys();
        $match = collect(is_array($data['match'] ?? null) ? $data['match'] : [])
            ->map(fn ($v) => (string) $v)
            ->filter(fn (string $v) => in_array($v, $allowed, true))
            ->unique()
            ->values()
            ->all();

        if ($scope !== self::SCOPE_OFF && $match === []) {
            $match = [
                self::MATCH_SERVICE_ID,
                self::MATCH_SHORT_CODE,
                self::MATCH_MONTH,
            ];
        }

        return new self($scope, $match, $action);
    }

    /**
     * @return array{scope: string, match: list<string>, action: string}
     */
    public function toArray(): array
    {
        return [
            'scope' => $this->scope,
            'match' => array_values($this->match),
            'action' => $this->action,
        ];
    }

    /** True when checks should change row status / block send. */
    public function enforces(): bool
    {
        return $this->scope !== self::SCOPE_OFF
            && $this->match !== []
            && $this->action === self::ACTION_BLOCK;
    }

    public function checksWithinImport(): bool
    {
        return $this->enforces()
            && in_array($this->scope, [self::SCOPE_WITHIN_IMPORT, self::SCOPE_BOTH], true);
    }

    public function checksPriorSends(): bool
    {
        return $this->enforces()
            && in_array($this->scope, [self::SCOPE_PRIOR_SENDS, self::SCOPE_BOTH], true);
    }

    public function matches(string $field): bool
    {
        return in_array($field, $this->match, true);
    }
}
