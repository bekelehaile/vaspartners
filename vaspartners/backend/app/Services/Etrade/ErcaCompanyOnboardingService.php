<?php

namespace App\Services\Etrade;

use App\Models\Contact;
use App\Services\CompanyMembershipService;
use App\Support\TinNumber;
use App\Support\ErcaTinWriteGuard;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * New-company onboarding: partner searches ERCA by TIN number, sees limited info,
 * consents to create (auto-approved) or declines (caller logs them out).
 */
class ErcaCompanyOnboardingService
{
    public function __construct(
        protected EtradeTinLookupService $lookup,
        protected CompanyMembershipService $membership,
        protected ErcaPortalSearchGuard $searchGuard,
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
    public function previewByTin(Contact $contact, string $rawTin, ?int $ignoreCompanyId = null): array
    {
        $tin = TinNumber::normalize($rawTin);
        if (! TinNumber::isValid($tin)) {
            throw ValidationException::withMessages([
                'company_tin' => TinNumber::message(),
            ]);
        }

        $this->searchGuard->assertCanSearch($contact, $tin);
        $this->membership->assertTinAvailableForCreate($tin, $ignoreCompanyId);

        if (! $this->lookup->enabled()) {
            throw ValidationException::withMessages([
                'company_tin' => $this->lookup->unavailableMessage(),
            ]);
        }

        $cachedHit = $this->lookup->hasCachedResult($tin);
        if (! $cachedHit && ! $this->acquireLookupSlot()) {
            throw ValidationException::withMessages([
                'company_tin' => 'TIN number verification is busy. Please try again in a few minutes.',
            ]);
        }

        $this->searchGuard->recordSearch($contact, $tin);

        $result = $this->lookup->lookup($tin);

        if (! empty($result['raw']['unavailable'])) {
            throw ValidationException::withMessages([
                'company_tin' => $this->lookup->unavailableMessage(),
            ]);
        }

        if (! $result['found']) {
            throw ValidationException::withMessages([
                'company_tin' => 'No taxpayer found for this TIN number in ERCA.',
            ]);
        }

        $legal = trim((string) ($result['legal_name'] ?: $result['business_name'] ?: ''));
        if ($legal === '') {
            throw ValidationException::withMessages([
                'company_tin' => 'ERCA returned this TIN number without a usable legal name.',
            ]);
        }

        $businessName = isset($result['business_name']) ? trim((string) $result['business_name']) : '';
        $contactFields = app(ErcaTinVerificationService::class)->contactFieldsFromLookup($result);
        $token = Str::random(40);
        $payload = array_merge([
            'contact_id' => $contact->id,
            'tin' => $tin,
            'legal_name' => $legal,
            'business_name' => $businessName !== '' && $businessName !== $legal ? $businessName : null,
            'entity_type' => $result['entity_type'] ?? null,
            'tax_centre' => $result['tax_centre'] ?? null,
            'region' => $result['region'] ?? null,
            'city' => $result['city'] ?? null,
        ], $contactFields);

        Cache::put($this->tokenKey($token), $payload, now()->addMinutes(15));

        // Preview already hit ERCA successfully — allow consent create/update in this request.
        ErcaTinWriteGuard::approve($tin);

        return [
            'preview_token' => $token,
            'tin' => $tin,
            'legal_name' => $legal,
            'business_name' => $payload['business_name'],
            'entity_type' => $payload['entity_type'],
            'tax_centre' => $payload['tax_centre'],
            'region' => $payload['region'],
            'city' => $payload['city'],
            'phone' => $payload['phone'] ?? null,
            'email' => $payload['email'] ?? null,
            'address' => $payload['address'] ?? null,
        ];
    }

    /**
     * Consent to create company from a valid ERCA preview token.
     */
    public function createFromConsent(Contact $contact, string $previewToken, string $address): Contact
    {
        // Read (do not consume) until create succeeds — uniqueness failures must not burn the token.
        $cached = Cache::get($this->tokenKey($previewToken));
        if (! is_array($cached) || (int) ($cached['contact_id'] ?? 0) !== (int) $contact->id) {
            throw ValidationException::withMessages([
                'preview_token' => 'ERCA preview expired. Search the TIN number again.',
            ]);
        }

        $tin = (string) ($cached['tin'] ?? '');
        $legal = trim((string) ($cached['legal_name'] ?? ''));
        if (! TinNumber::isValid($tin) || $legal === '') {
            throw ValidationException::withMessages([
                'preview_token' => 'Invalid ERCA preview. Search the TIN number again.',
            ]);
        }

        $address = trim($address);
        if (strlen($address) < 5) {
            throw ValidationException::withMessages([
                'company_address' => 'Enter the company address (at least 5 characters).',
            ]);
        }

        // Fail fast before create — TIN must never be duplicated.
        $this->membership->assertTinAvailableForCreate($tin);

        // Re-confirm against ERCA (or result cache) before the local insert.
        app(ErcaTinVerificationService::class)->assertTinExistsInErca($tin);

        $ercaPhone = isset($cached['phone']) ? trim((string) $cached['phone']) : '';
        $ercaEmail = isset($cached['email']) ? trim((string) $cached['email']) : '';
        $ercaAddress = isset($cached['address']) ? trim((string) $cached['address']) : '';

        $created = $this->membership->createApprovedCompanyFromErca(
            $contact,
            [
                'company_name' => $legal,
                'company_tin' => $tin,
                'company_address' => $address,
                'legal_name' => $legal,
                'company_phone' => $ercaPhone !== '' ? $ercaPhone : null,
                'company_email' => $ercaEmail !== '' ? $ercaEmail : null,
                'erca_address' => $ercaAddress !== '' ? $ercaAddress : null,
            ],
        );

        Cache::forget($this->tokenKey($previewToken));

        return $created;
    }

    /**
     * Apply ERCA preview to the contact's current company (TIN + legal name update).
     */
    public function updateExistingFromConsent(Contact $contact, string $previewToken): Contact
    {
        $cached = Cache::get($this->tokenKey($previewToken));
        if (! is_array($cached) || (int) ($cached['contact_id'] ?? 0) !== (int) $contact->id) {
            throw ValidationException::withMessages([
                'preview_token' => 'ERCA preview expired. Search the TIN number again.',
            ]);
        }

        $tin = (string) ($cached['tin'] ?? '');
        $legal = trim((string) ($cached['legal_name'] ?? ''));
        if (! TinNumber::isValid($tin) || $legal === '') {
            throw ValidationException::withMessages([
                'preview_token' => 'Invalid ERCA preview. Search the TIN number again.',
            ]);
        }

        ErcaTinWriteGuard::approve($tin);

        // Prefer live/cached ERCA confirmation over token alone.
        app(ErcaTinVerificationService::class)->assertTinExistsInErca($tin);

        $updated = $this->membership->updateCompanyFromErcaPreview(
            $contact,
            [
                'company_tin' => $tin,
                'legal_name' => $legal,
                'company_phone' => isset($cached['phone']) ? (string) $cached['phone'] : null,
                'company_email' => isset($cached['email']) ? (string) $cached['email'] : null,
                'company_address' => isset($cached['address']) ? (string) $cached['address'] : null,
            ],
        );

        Cache::forget($this->tokenKey($previewToken));

        return $updated;
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
