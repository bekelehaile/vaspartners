<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Carbon;

/**
 * Human-readable public ids: year+month+day+hour + two random digits.
 * Example: 202607230923
 */
final class TimestampPublicId
{
    /**
     * @param  (Closure(string): bool)|null  $exists
     */
    public static function generate(CarbonInterface|string|null $at = null, ?Closure $exists = null): string
    {
        $moment = self::moment($at);
        $exists ??= static fn (string $id): bool => false;
        $prefix = $moment->format('YmdH');

        for ($attempt = 0; $attempt < 50; $attempt++) {
            $id = $prefix.str_pad((string) random_int(0, 99), 2, '0', STR_PAD_LEFT);
            if (! $exists($id)) {
                return $id;
            }
        }

        // Same-hour collision fallback: include minutes.
        return $moment->format('YmdHi').str_pad((string) random_int(0, 99), 2, '0', STR_PAD_LEFT);
    }

    public static function isValid(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\d{12,14}$/', $value) === 1;
    }

    /** Old MVAS-style hex ticket ids (e.g. 11B4BA6760). */
    public static function looksLikeLegacyHexId(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[0-9A-Fa-f]{8,12}$/', $value) === 1;
    }

    public static function looksLikeUlid(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[0-9a-hjkmnp-z]{26}$/i', $value) === 1;
    }

    private static function moment(CarbonInterface|string|null $at): CarbonInterface
    {
        if ($at instanceof CarbonInterface) {
            return $at->copy();
        }

        return Carbon::parse($at ?? now());
    }
}
