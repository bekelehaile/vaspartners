<?php

namespace App\Services\Etrade;

use App\Models\Contact;
use App\Services\CompanyMembershipService;
use App\Support\TinNumber;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * New-company onboarding: partner searches ERCA by TIN, sees limited info,
 * consents to create (auto-approved) or declines (caller logs them out).
 */
class ErcaCompanyOnboardingService
{
    public function __construct(
        protected EtradeTinLookupService $lookup,
        protected CompanyMembershipService $membership,
    ) {}

    /**
     * @return array{
     *   preview_token: string,
     *   tin: string,
     *   legal_name: string,
     *   business_name: ?string,
     *   entity_type: ?string,
     *   tax_centre: ?string,
     *   region: ?string,
     *   city: ?string
     * }
     */
    public function previewByTin(Contact $contact, string $rawTin): array
    {
        $tin = TinNumber::normalize($rawTin);
        if (! TinNumber::isValid($tin)) {
            throw ValidationException::withMessages([
                'company_tin' => TinNumber::message(),
            ]);
        }

        $this->membership->assertTinAvailableForCreate($tin);

        if (! $this->lookup->enabled()) {
            throw ValidationException::withMessages([
                'company_tin' => 'TIN verification is temporarily unavailable. Try again shortly.',
            ]);
        }

        if (! $this->acquireLookupSlot()) {
            throw ValidationException::withMessages([
                'company_tin' => 'TIN verification is busy. Please try again in a few minutes.',
            ]);
        }

        $result = $this->lookup->lookup($tin);

        if (! empty($result['raw']['unavailable'])) {
            throw ValidationException::withMessages([
                'company_tin' => 'Unable to reach the national TIN registry. Please try again shortly.',
            ]);
        }

        if (! $result['found']) {
            throw ValidationException::withMessages([
                'company_tin' => 'No taxpayer found for this TIN in ERCA.',
            ]);
        }

        $legal = trim((string) ($result['legal_name'] ?: $result['business_name'] ?: ''));
        if ($legal === '') {
            throw ValidationException::withMessages([
                'company_tin' => 'ERCA returned this TIN without a usable legal name.',
            ]);
        }

        $businessName = isset($result['business_name']) ? trim((string) $result['business_name']) : '';
        $token = Str::random(40);
        $payload = [
            'contact_id' => $contact->id,
            'tin' => $tin,
            'legal_name' => $legal,
            'business_name' => $businessName !== '' && $businessName !== $legal ? $businessName : null,
            'entity_type' => $result['entity_type'] ?? null,
            'tax_centre' => $result['tax_centre'] ?? null,
            'region' => $result['region'] ?? null,
            'city' => $result['city'] ?? null,
        ];

        Cache::put($this->tokenKey($token), $payload, now()->addMinutes(15));

        return [
            'preview_token' => $token,
            'tin' => $tin,
            'legal_name' => $legal,
            'business_name' => $payload['business_name'],
            'entity_type' => $payload['entity_type'],
            'tax_centre' => $payload['tax_centre'],
            'region' => $payload['region'],
            'city' => $payload['city'],
        ];
    }

    /**
     * Consent to create company from a valid ERCA preview token.
     */
    public function createFromConsent(Contact $contact, string $previewToken, string $address): Contact
    {
        $cached = Cache::pull($this->tokenKey($previewToken));
        if (! is_array($cached) || (int) ($cached['contact_id'] ?? 0) !== (int) $contact->id) {
            throw ValidationException::withMessages([
                'preview_token' => 'ERCA preview expired. Search the TIN again.',
            ]);
        }

        $tin = (string) ($cached['tin'] ?? '');
        $legal = trim((string) ($cached['legal_name'] ?? ''));
        if (! TinNumber::isValid($tin) || $legal === '') {
            throw ValidationException::withMessages([
                'preview_token' => 'Invalid ERCA preview. Search the TIN again.',
            ]);
        }

        $address = trim($address);
        if (strlen($address) < 5) {
            throw ValidationException::withMessages([
                'company_address' => 'Enter the company address (at least 5 characters).',
            ]);
        }

        $this->membership->assertTinAvailableForCreate($tin);

        return $this->membership->createApprovedCompanyFromErca(
            $contact,
            [
                'company_name' => $legal,
                'company_tin' => $tin,
                'company_address' => $address,
                'legal_name' => $legal,
            ],
        );
    }

    public function forgetPreview(string $previewToken): void
    {
        if ($previewToken !== '') {
            Cache::forget($this->tokenKey($previewToken));
        }
    }

    protected function tokenKey(string $token): string
    {
        return 'erca:company-preview:'.$token;
    }

    protected function acquireLookupSlot(): bool
    {
        $perMinute = max(1, (int) config('services.etrade.global_lookups_per_minute', 8));
        $key = 'etrade:global-lookups:'.now()->format('YmdHi');
        $count = (int) Cache::get($key, 0);
        if ($count >= $perMinute) {
            return false;
        }
        Cache::put($key, $count + 1, now()->addMinutes(2));

        return true;
    }
}
