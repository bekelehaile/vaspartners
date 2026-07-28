<?php

namespace App\Support;

/**
 * Values accepted for portal phone-OTP first-time profile.
 */
final class PortalProfileOptions
{
    public const DEFAULT_NATIONALITY = 'Ethiopia';

    public const GENDERS = [
        'Male',
        'Female',
    ];

    /**
     * Common nationalities for partner self-registration (Ethiopia first / default).
     *
     * @return list<string>
     */
    public static function nationalities(): array
    {
        return [
            'Ethiopia',
            'Eritrea',
            'Djibouti',
            'Somalia',
            'Sudan',
            'South Sudan',
            'Kenya',
            'Uganda',
            'Tanzania',
            'Rwanda',
            'Burundi',
            'Egypt',
            'Nigeria',
            'Ghana',
            'South Africa',
            'China',
            'India',
            'United Arab Emirates',
            'Saudi Arabia',
            'Turkey',
            'United Kingdom',
            'United States',
            'Canada',
            'Germany',
            'France',
            'Italy',
            'Netherlands',
            'Other',
        ];
    }

    public static function isValidGender(?string $gender): bool
    {
        return in_array((string) $gender, self::GENDERS, true);
    }

    public static function isValidNationality(?string $nationality): bool
    {
        return in_array((string) $nationality, self::nationalities(), true);
    }
}
