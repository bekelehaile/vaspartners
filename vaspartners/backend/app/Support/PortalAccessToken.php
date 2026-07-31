<?php

namespace App\Support;

use App\Models\Contact;
use DateTimeInterface;

/**
 * Issue / rotate partner portal Sanctum tokens with a finite lifetime.
 */
final class PortalAccessToken
{
    public const NAME_OTP = 'phone_otp';

    public const NAME_FAYDA = 'fayda';

    /**
     * Default 30 minutes; override with SANCTUM_TOKEN_EXPIRATION (minutes).
     */
    public static function ttlMinutes(): int
    {
        $configured = config('sanctum.expiration');
        if ($configured === null || $configured === '' || (int) $configured <= 0) {
            return 60 * 24;
        }

        return (int) $configured;
    }

    public static function expiresAt(): DateTimeInterface
    {
        return now()->addMinutes(self::ttlMinutes());
    }

    /**
     * Revoke prior portal tokens, then issue a new one that expires.
     */
    public static function issue(Contact $contact, string $name): string
    {
        $contact->tokens()
            ->whereIn('name', [self::NAME_OTP, self::NAME_FAYDA])
            ->delete();

        return $contact->createToken($name, ['*'], self::expiresAt())->plainTextToken;
    }
}
