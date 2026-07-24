<?php

namespace App\Support;

use App\Models\User;
use App\Services\SmsService;

final class AdminLoginResolver
{
    /**
     * Resolve an active admin user by email, phone number, or username.
     */
    public static function resolve(string $login): ?User
    {
        $login = trim($login);
        if ($login === '') {
            return null;
        }

        if (filter_var($login, FILTER_VALIDATE_EMAIL)) {
            return User::query()
                ->where('is_active', true)
                ->whereRaw('LOWER(email) = ?', [strtolower($login)])
                ->first();
        }

        $sms = app(SmsService::class);
        $normalizedPhone = $sms->normalizePhone($login);

        $phoneCandidates = [];
        if ($normalizedPhone !== '' && preg_match('/^\d{9,15}$/', $normalizedPhone)) {
            $phoneCandidates = array_values(array_unique(array_filter([
                $login,
                $normalizedPhone,
                '0'.$normalizedPhone,
                '251'.$normalizedPhone,
                '+251'.$normalizedPhone,
            ])));
        }

        return User::query()
            ->where('is_active', true)
            ->where(function ($query) use ($login, $phoneCandidates) {
                $query->whereRaw('LOWER(username) = ?', [mb_strtolower($login)]);

                if ($phoneCandidates !== []) {
                    $query->orWhere(function ($phoneQuery) use ($phoneCandidates) {
                        foreach ($phoneCandidates as $i => $candidate) {
                            if ($i === 0) {
                                $phoneQuery->where('phone', $candidate);
                            } else {
                                $phoneQuery->orWhere('phone', $candidate);
                            }
                        }
                    });
                }
            })
            ->first();
    }
}
