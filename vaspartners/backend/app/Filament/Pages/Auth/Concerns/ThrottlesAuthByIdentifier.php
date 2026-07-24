<?php

namespace App\Filament\Pages\Auth\Concerns;

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Per-identifier auth throttling (identifier + IP), same pattern as fixedservices.
 */
trait ThrottlesAuthByIdentifier
{
    protected function authThrottleKey(string $prefix): string
    {
        return $prefix.':'.sha1(
            Str::transliterate(Str::lower($this->authRateLimitIdentifier())).'|'.request()->ip()
        );
    }

    /**
     * @throws ValidationException
     */
    protected function ensureNotAuthThrottled(
        string $prefix,
        int $maxAttempts = 5,
        string $errorField = 'data.phone',
    ): void {
        $key = $this->authThrottleKey($prefix);

        if (! RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            return;
        }

        $seconds = RateLimiter::availableIn($key);

        throw ValidationException::withMessages([
            $errorField => __('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => (int) ceil($seconds / 60),
            ]),
        ]);
    }

    protected function hitAuthThrottle(string $prefix, int $decaySeconds = 60): void
    {
        RateLimiter::hit($this->authThrottleKey($prefix), $decaySeconds);
    }

    protected function clearAuthThrottle(string $prefix): void
    {
        RateLimiter::clear($this->authThrottleKey($prefix));
    }

    protected function authRateLimitIdentifier(): string
    {
        return '';
    }
}
