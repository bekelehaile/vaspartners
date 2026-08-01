<?php

namespace App\Services;

use App\Enums\IdentityVerifiedVia;
use App\Models\Contact;
use App\Services\Crm\CrmCustomerLookupService;
use App\Support\PhoneNumber;
use App\Support\PortalProfileOptions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Personal KYC: Fayda or CRM (BSS GetCustomer).
 * Journey for new partners: OTP → company TIN (ERCA) confirm → CRM identity confirm.
 * CRM is staged for explicit consent after a TIN-validated company exists.
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
     * After OTP/Fayda auth (or after ERCA company create).
     *
     * @return array{
     *   needs_consent: bool,
     *   needs_manual_name: bool,
     *   needs_company: bool,
     *   crm_available: bool,
     *   proposal: ?array<string, mixed>,
     *   verified_via: ?string
     * }
     */
    public function resolveAfterAuth(Contact $contact): array
    {
        $membership = app(CompanyMembershipService::class);
        $hasTinCompany = $membership->contactHasTinValidatedCompany($contact);

        if ($this->isVerified($contact)) {
            $via = $contact->identity_verified_via
                ?? ($contact->fayda_verified ? IdentityVerifiedVia::Fayda->value : null);
            $this->autoApproveOwnedCompanies($contact);

            return [
                'needs_consent' => false,
                'needs_manual_name' => false,
                'needs_company' => ! $hasTinCompany,
                'crm_available' => $this->crm->enabled(),
                'proposal' => null,
                'verified_via' => $via,
            ];
        }

        // Company TIN first — do not fetch CRM until the partner has a validated company.
        if (! $hasTinCompany) {
            return [
                'needs_consent' => false,
                'needs_manual_name' => false,
                'needs_company' => true,
                'crm_available' => $this->crm->enabled(),
                'proposal' => null,
                'verified_via' => null,
            ];
        }

        $lookup = $this->crm->lookupByPhone((string) $contact->phone_number);

        if ($lookup === null) {
            return [
                'needs_consent' => false,
                'needs_manual_name' => $this->needsManualName($contact),
                'needs_company' => false,
                'crm_available' => false,
                'proposal' => null,
                'verified_via' => null,
            ];
        }

        if (! ($lookup['found'] ?? false) || blank($lookup['customer_name'] ?? null)) {
            return [
                'needs_consent' => false,
                'needs_manual_name' => $this->needsManualName($contact),
                'needs_company' => false,
                'crm_available' => true,
                'proposal' => null,
                'verified_via' => null,
            ];
        }

        $proposal = [
            'source' => IdentityVerifiedVia::Crm->value,
            'phone' => PhoneNumber::normalize((string) ($lookup['phone'] ?? $contact->phone_number)),
            'name' => (string) $lookup['customer_name'],
            'email' => $lookup['email'] ?? null,
            'gender' => $lookup['gender'] ?? null,
            'nationality' => $lookup['nationality'] ?? null,
            'birthdate' => $lookup['birthdate'] ?? null,
            'identification_type' => $lookup['identification_type'] ?? null,
            'identification_number' => $lookup['identification_number'] ?? null,
            'snapshot' => $lookup['raw'],
        ];

        Cache::put($this->cacheKey($contact), $proposal, now()->addMinutes(30));

        return [
            'needs_consent' => true,
            'needs_manual_name' => false,
            'needs_company' => false,
            'crm_available' => true,
            'proposal' => $proposal,
            'verified_via' => null,
        ];
    }

    /**
     * Partner accepts CRM identity after confirming company TIN.
     */
    public function acceptCrmConsent(Contact $contact): Contact
    {
        if ($this->isVerified($contact)) {
            $this->autoApproveOwnedCompanies($contact);

            return $contact->fresh() ?? $contact;
        }

        $proposal = Cache::get($this->cacheKey($contact));
        if (! is_array($proposal) || blank($proposal['name'] ?? null)) {
            throw ValidationException::withMessages([
                'identity' => 'Identity details are no longer available. Enter your name manually or try again.',
            ]);
        }

        $contact = $this->applyCrmProposal($contact, $proposal);
        Cache::forget($this->cacheKey($contact));
        $this->autoApproveOwnedCompanies($contact);

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
        $cached = Cache::get($this->cacheKey($contact));

        return is_array($cached) && filled($cached['name'] ?? null) ? $cached : null;
    }

    /**
     * @param  array<string, mixed>  $proposal
     */
    protected function applyCrmProposal(Contact $contact, array $proposal): Contact
    {
        $name = trim((string) $proposal['name']);
        $contact->syncFromFayda([
            'sub' => $contact->sub ?: ('otp-'.$contact->phone_number),
            'name' => $name,
            'phone_number' => $contact->phone_number,
            'email' => $proposal['email'] ?? $contact->email,
            'gender' => $proposal['gender'] ?? $contact->gender,
            'nationality' => $proposal['nationality']
                ?? ($contact->nationality ?: PortalProfileOptions::DEFAULT_NATIONALITY),
            'birthdate' => $proposal['birthdate'] ?? $contact->birthdate,
            'identification_type' => $proposal['identification_type']
                ?? ($contact->identification_type ?: '2'),
            'identification_number' => $proposal['identification_number']
                ?? ($contact->identification_number ?: ('crm-'.$contact->phone_number)),
            'address' => $contact->address,
        ]);

        $contact->markIdentityVerified(
            IdentityVerifiedVia::Crm,
            is_array($proposal['snapshot'] ?? null) ? $proposal['snapshot'] : $proposal,
        );

        return $contact->fresh() ?? $contact;
    }

    protected function autoApproveOwnedCompanies(Contact $contact): void
    {
        try {
            app(CompanyMembershipService::class)
                ->autoApproveOwnedCompaniesAfterIdentityVerification($contact->fresh() ?? $contact);
        } catch (Throwable $e) {
            report($e);
        }
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
