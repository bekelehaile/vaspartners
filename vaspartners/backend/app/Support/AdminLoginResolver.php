<?php

namespace App\Support;

use App\Models\User;
use App\Services\SmsService;

final class AdminLoginResolver
{
    /**
     * Resolve an active admin user by phone number only.
     */
    public static function resolve(string $login): ?User
    {
        $login = trim($login);
        if ($login === '') {
            return null;
        }

        $sms = app(SmsService::class);
        $normalizedPhone = $sms->normalizePhone($login);

        // Reject clearly non-phone identifiers (email / username text).
        if ($normalizedPhone === '' || ! preg_match('/^\d{9,15}$/', $normalizedPhone)) {
            return null;
        }

        $candidates = array_values(array_unique(array_filter([
            $login,
            $normalizedPhone,
            '0'.$normalizedPhone,
            '251'.$normalizedPhone,
            '+251'.$normalizedPhone,
        ])));

        return User::query()
            ->where('is_active', true)
            ->where(function ($query) use ($candidates) {
                foreach ($candidates as $i => $candidate) {
                    if ($i === 0) {
                        $query->where('phone', $candidate);
                    } else {
                        $query->orWhere('phone', $candidate);
                    }
                }
            })
            ->first();
    }
}
