<?php

namespace App\Services;

use App\Enums\IdentityVerifiedVia;
use App\Models\Contact;
use App\Services\Crm\CrmCustomerLookupService;
use App\Support\PhoneNumber;
use App\Support\PortalProfileOptions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * Personal KYC: Fayda or CRM. Once verified, skip re-fetch on later logins.
 */
class ContactIdentityService
{
    public const CACHE_PREFIX = 'vas:crm-identity-consent:';

    public function __construct(
        private readonly CrmCustomerLookupService $crm,
    ) {}

    public function isVerified(Contact $contact): bool
    {
        if ($contact->identity_verified_via) {
            return true;
        }

        // Legacy sticky Fayda flag.
        return (bool) $contact->fayda_verified;
    }

    /**
     * After OTP/Fayda auth: if unverified, try CRM and stage a consent proposal.
     *
     * @return array{
     *   needs_consent: bool,
     *   needs_manual_name: bool,
     *   crm_available: bool,
     *   proposal: ?array<string, mixed>,
     *   verified_via: ?string
     * }
     */
    public function resolveAfterAuth(Contact $contact): array
    {
        if ($this->isVerified($contact)) {
            $via = $contact->identity_verified_via
                ?? ($contact->fayda_verified ? IdentityVerifiedVia::Fayda->value : null);

            return [
                'needs_consent' => false,
                'needs_manual_name' => false,
                'crm_available' => $this->crm->enabled(),
                'proposal' => null,
                'verified_via' => $via,
            ];
        }

        $lookup = $this->crm->lookupByPhone((string) $contact->phone_number);

        if ($lookup === null) {
            return [
                'needs_consent' => false,
                'needs_manual_name' => $this->needsManualName($contact),
                'crm_available' => false,
                'proposal' => null,
                'verified_via' => null,
            ];
        }

        if (! ($lookup['found'] ?? false) || blank($lookup['customer_name'] ?? null)) {
            return [
                'needs_consent' => false,
                'needs_manual_name' => $this->needsManualName($contact),
                'crm_available' => true,
                'proposal' => null,
                'verified_via' => null,
            ];
        }

        $proposal = [
            'source' => IdentityVerifiedVia::Crm->value,
            'phone' => PhoneNumber::normalize((string) $contact->phone_number),
            'name' => (string) $lookup['customer_name'],
            'customer_type' => $lookup['customer_type'],
            'primary_offer_name' => $lookup['primary_offer_name'],
            'service_numbers' => $lookup['service_numbers'],
            'region' => $lookup['region'],
            'zone' => $lookup['zone'],
            'snapshot' => $lookup['raw'],
        ];

        Cache::put($this->cacheKey($contact), $proposal, now()->addMinutes(20));

        return [
            'needs_consent' => true,
            'needs_manual_name' => false,
            'crm_available' => true,
            'proposal' => [
                'source' => $proposal['source'],
                'phone' => $proposal['phone'],
                'name' => $proposal['name'],
                'customer_type' => $proposal['customer_type'],
                'primary_offer_name' => $proposal['primary_offer_name'],
                'service_numbers' => $proposal['service_numbers'],
            ],
            'verified_via' => null,
        ];
    }

    /**
     * Partner accepts CRM identity shown on the consent screen.
     */
    public function acceptCrmConsent(Contact $contact): Contact
    {
        if ($this->isVerified($contact)) {
            return $contact->fresh() ?? $contact;
        }

        $proposal = Cache::get($this->cacheKey($contact));
        if (! is_array($proposal) || blank($proposal['name'] ?? null)) {
            // Re-fetch once if cache expired.
            $resolved = $this->resolveAfterAuth($contact);
            if (! ($resolved['needs_consent'] ?? false)) {
                throw ValidationException::withMessages([
                    'identity' => 'CRM identity is no longer available. Enter your name manually or try again.',
                ]);
            }
            $proposal = Cache::get($this->cacheKey($contact));
        }

        if (! is_array($proposal) || blank($proposal['name'] ?? null)) {
            throw ValidationException::withMessages([
                'identity' => 'CRM identity consent expired. Sign in again to refresh.',
            ]);
        }

        $name = trim((string) $proposal['name']);
        $contact->syncFromFayda([
            'sub' => $contact->sub ?: ('otp-'.$contact->phone_number),
            'name' => $name,
            'phone_number' => $contact->phone_number,
            'email' => $contact->email,
            'gender' => $contact->gender,
            'nationality' => $contact->nationality ?: PortalProfileOptions::DEFAULT_NATIONALITY,
            'identification_type' => $contact->identification_type ?: '2',
            'identification_number' => $contact->identification_number ?: ('crm-'.$contact->phone_number),
            'address' => $contact->address,
        ]);

        $contact->markIdentityVerified(
            IdentityVerifiedVia::Crm,
            is_array($proposal['snapshot'] ?? null) ? $proposal['snapshot'] : $proposal,
        );

        Cache::forget($this->cacheKey($contact));

        return $contact->fresh() ?? $contact;
    }

    /**
     * Decline CRM match — clear proposal; partner may set name manually.
     */
    public function declineCrmConsent(Contact $contact): void
    {
        Cache::forget($this->cacheKey($contact));
    }

    public function updateManualName(Contact $contact, string $name): Contact
    {
        if ($this->isVerified($contact)) {
            throw ValidationException::withMessages([
                'name' => 'Identity is already verified and cannot be changed here.',
            ]);
        }

        $name = trim($name);
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            throw ValidationException::withMessages([
                'name' => 'Enter your full name (2–120 characters).',
            ]);
        }

        $contact->syncFromFayda([
            'sub' => $contact->sub ?: ('otp-'.$contact->phone_number),
            'name' => $name,
            'phone_number' => $contact->phone_number,
            'email' => $contact->email,
            'gender' => $contact->gender,
            'nationality' => $contact->nationality ?: PortalProfileOptions::DEFAULT_NATIONALITY,
            'identification_type' => $contact->identification_type ?: '2',
            'identification_number' => $contact->identification_number ?: ('otp-'.$contact->phone_number),
        ]);

        // Manual name is not KYC-verified (neither Fayda nor CRM).
        return $contact->fresh() ?? $contact;
    }

    public function pendingProposal(Contact $contact): ?array
    {
        if ($this->isVerified($contact)) {
            return null;
        }

        $proposal = Cache::get($this->cacheKey($contact));
        if (! is_array($proposal)) {
            return null;
        }

        return [
            'source' => $proposal['source'] ?? IdentityVerifiedVia::Crm->value,
            'phone' => $proposal['phone'] ?? null,
            'name' => $proposal['name'] ?? null,
            'customer_type' => $proposal['customer_type'] ?? null,
            'primary_offer_name' => $proposal['primary_offer_name'] ?? null,
            'service_numbers' => $proposal['service_numbers'] ?? [],
        ];
    }

    protected function needsManualName(Contact $contact): bool
    {
        $name = trim((string) $contact->name);

        return $name === '' || strcasecmp($name, 'Partner') === 0 || str_starts_with($name, 'Partner ');
    }

    protected function cacheKey(Contact $contact): string
    {
        return self::CACHE_PREFIX.$contact->id;
    }
}
