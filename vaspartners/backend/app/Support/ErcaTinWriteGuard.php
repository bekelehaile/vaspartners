<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

/**
 * Request-scoped gate: a valid Ethiopian TIN may only be committed to the local DB
 * after a successful ERCA / eTrade lookup in this request (or an explicit system bypass).
 *
 * Technical callers (API clients, artisan, Filament) cannot skip this by writing
 * Company rows directly — the Company model enforces it on save.
 */
final class ErcaTinWriteGuard
{
    protected static bool $bypass = false;

    /** @var array<string, true> */
    protected static array $approvedTins = [];

    /**
     * Mark a TIN as ERCA-confirmed for the remainder of this request.
     */
    public static function approve(string $tin): void
    {
        $normalized = TinNumber::normalize($tin);
        if (! TinNumber::isValid($normalized)) {
            return;
        }

        self::$approvedTins[$normalized] = true;
    }

    public static function isApproved(string $tin): bool
    {
        if (self::$bypass) {
            return true;
        }

        $normalized = TinNumber::normalize($tin);

        return TinNumber::isValid($normalized) && isset(self::$approvedTins[$normalized]);
    }

    /**
     * @throws ValidationException
     */
    public static function assertCanCommit(string $tin, string $field = 'tin'): void
    {
        $raw = trim($tin);

        // Legacy placeholders with letters (MVAS-*) are never treated as Ethiopian TINs,
        // even if digit extraction would yield 10 digits.
        if ($raw === '' || preg_match('/[A-Za-z]/', $raw)) {
            return;
        }

        $normalized = TinNumber::normalize($raw);

        if (! TinNumber::isValid($normalized)) {
            return;
        }

        if (self::isApproved($normalized)) {
            return;
        }

        throw ValidationException::withMessages([
            $field => 'This TIN number must be confirmed in the national ERCA registry before it can be saved. Search ERCA and consent first — direct API or admin writes are not allowed.',
        ]);
    }

    /**
     * Migrations / remount tooling only. Never expose to portal or public API.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function without(callable $callback): mixed
    {
        $previous = self::$bypass;
        self::$bypass = true;

        try {
            return $callback();
        } finally {
            self::$bypass = $previous;
        }
    }

    /** @internal tests */
    public static function reset(): void
    {
        self::$bypass = false;
        self::$approvedTins = [];
    }
}
