<?php

namespace App\Services;

use App\Enums\CompanyApprovalStatus;
use App\Enums\CompanyChangeStatus;
use App\Enums\CompanyChangeType;
use App\Enums\CompanyMemberPermission;
use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\CompanyChangeRequest;
use App\Models\CompanyMembership;
use App\Models\CompanyStatusHistory;
use App\Models\Contact;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Etrade\ErcaPortalSearchGuard;
use App\Services\Etrade\ErcaTinVerificationService;
use App\Support\TinNumber;
use App\Support\PhoneNumber;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CompanyMembershipService
{
    public function __construct(
        protected PartnerNotificationService $notifications,
        protected ErcaTinVerificationService $ercaTin,
        protected ErcaPortalSearchGuard $ercaSearchGuard,
    ) {}

    public function maxDocKb(): int
    {
        return max(1, (int) config('vas.company_change_doc_max_kb', 5120));
    }

    /**
     * Create a company profile as owner — stays pending until admin verifies required info.
     * TIN uniquely identifies the company. Phone/email are shared: first company uses the
     * partner identity; additional companies copy phone/email from an existing company.
     *
     * @param  array{company_name: string, company_tin: string, company_address: string}  $data
     */
    public function createCompanyForContact(Contact $contact, array $data): Contact
    {
        if ($this->pendingRequestFor($contact)) {
            throw ValidationException::withMessages([
                'company' => 'You already have a pending company request. Wait for a decision.',
            ]);
        }

        $tin = $this->normalizeEthiopianTin($data['company_tin']);
        $this->assertUniqueTin($tin);

        // Must exist in ERCA before any local company row is created.
        $this->ercaTin->assertTinExistsInErca($tin);

        $contact->loadMissing(['memberships.company', 'company']);
        $creatingAdditional = $contact->memberships->isNotEmpty();
        [$companyPhone, $companyEmail] = $this->resolveSharedCompanyContacts($contact);

        if ($companyPhone === '' || ! \App\Support\PhoneNumber::isValidLocalMobile($companyPhone)) {
            throw ValidationException::withMessages([
                'company' => $creatingAdditional
                    ? 'Your existing company has no usable phone. Update that company or sign in again.'
                    : 'Your signed-in phone number is required to create a company. Sign in again.',
            ]);
        }

        // Stay on an approved working company so the portal is not locked to a pending TIN.
        $keepApprovedContext = $creatingAdditional
            && (bool) $contact->current_company_id
            && $contact->hasActiveCompanyMembership()
            && $contact->company?->isApproved();

        $result = DB::transaction(function () use (
            $contact,
            $data,
            $tin,
            $companyPhone,
            $companyEmail,
            $keepApprovedContext,
        ) {
            $company = Company::query()->create([
                'name' => trim($data['company_name']),
                'tin' => $tin,
                'tin_validated' => false,
                'phone' => $companyPhone,
                'claim_phone' => $companyPhone,
                'revenue_phone' => $companyPhone,
                'email' => $companyEmail,
                'address' => trim($data['company_address']),
                'is_active' => false,
                'approval_status' => CompanyApprovalStatus::Pending,
                'created_by_contact_id' => $contact->id,
            ]);

            $this->linkContact(
                $contact,
                $company,
                CompanyRole::Owner,
                switchTo: ! $keepApprovedContext,
            );
            $fresh = $contact->fresh(['company', 'memberships.company']);
            $this->notifications->companyProfileSubmitted($fresh, $company);
            $this->autoApproveOwnedCompaniesAfterIdentityVerification($fresh);

            return [
                'contact' => $fresh->fresh(['company', 'memberships.company']),
                'company' => $company->fresh() ?? $company,
            ];
        });

        $this->safeErcaVerify($result['company'], force: true);

        return $result['contact']->fresh(['company', 'memberships.company']);
    }

    /**
     * Public TIN uniqueness check for ERCA onboarding preview.
     */
    public function assertTinAvailableForCreate(string $tin, ?int $ignoreCompanyId = null): void
    {
        $this->assertUniqueTin($this->normalizeEthiopianTin($tin), $ignoreCompanyId);
    }

    /**
     * @throws ValidationException
     */
    public function assertErcaIdentityEditable(Company $company): void
    {
        if (! $company->isErcaIdentityLocked()) {
            return;
        }

        throw ValidationException::withMessages([
            'company' => 'Company name and TIN number are locked after ERCA verification match. Contact Ethio telecom support if a correction is required.',
            'company_name' => 'Company name cannot be changed after ERCA match.',
            'company_tin' => 'TIN number cannot be changed after ERCA match.',
        ]);
    }

    /**
     * Create company after partner consents to ERCA registry match.
     * Auto-approves + marks TIN number validated (ERCA is the attestation).
     *
     * @param  array{company_name: string, company_tin: string, company_address: string, legal_name: string}  $data
     */
    public function createApprovedCompanyFromErca(Contact $contact, array $data): Contact
    {
        if ($this->pendingRequestFor($contact)) {
            throw ValidationException::withMessages([
                'company' => 'You already have a pending company request. Wait for a decision.',
            ]);
        }

        $tin = $this->normalizeEthiopianTin($data['company_tin']);
        $this->assertUniqueTin($tin);

        // Never trust caller-supplied TIN without ERCA (blocks service/API misuse).
        $this->ercaTin->assertTinExistsInErca($tin);

        $legal = \App\Services\Etrade\CompanyNameMatcher::titleCase(
            trim((string) ($data['legal_name'] ?: $data['company_name'])),
        );
        if ($legal === '') {
            throw ValidationException::withMessages([
                'company_name' => 'ERCA legal name is required.',
            ]);
        }

        $contact->loadMissing(['memberships.company', 'company']);
        $creatingAdditional = $contact->memberships->isNotEmpty();
        [$claimPhone, $companyEmail] = $this->resolveSharedCompanyContacts($contact);

        $ercaPhoneRaw = trim((string) ($data['company_phone'] ?? ''));
        $ercaPhone = '';
        if ($ercaPhoneRaw !== '' && \App\Support\PhoneNumber::isValidLocalMobile($ercaPhoneRaw)) {
            $ercaPhone = \App\Support\PhoneNumber::normalize($ercaPhoneRaw);
        }

        $ercaEmail = trim((string) ($data['company_email'] ?? ''));
        if ($ercaEmail !== '' && filter_var($ercaEmail, FILTER_VALIDATE_EMAIL)) {
            $companyEmail = strtolower($ercaEmail);
        }

        if ($claimPhone === '' || ! \App\Support\PhoneNumber::isValidLocalMobile($claimPhone)) {
            throw ValidationException::withMessages([
                'company' => $creatingAdditional
                    ? 'Your existing company has no usable phone. Update that company or sign in again.'
                    : 'Your signed-in phone number is required to create a company. Sign in again.',
            ]);
        }

        $keepApprovedContext = $creatingAdditional
            && (bool) $contact->current_company_id
            && $contact->hasActiveCompanyMembership()
            && $contact->company?->isApproved();

        $companyAddress = trim((string) ($data['company_address'] ?? ''));
        $ercaAddress = trim((string) ($data['erca_address'] ?? ''));
        if ($companyAddress === '' && $ercaAddress !== '') {
            $companyAddress = $ercaAddress;
        }

        $result = DB::transaction(function () use (
            $contact,
            $data,
            $tin,
            $legal,
            $claimPhone,
            $ercaPhone,
            $companyEmail,
            $companyAddress,
            $keepApprovedContext,
        ) {
            $company = Company::query()->create([
                'name' => $legal,
                'legal_name' => $legal,
                'tin' => $tin,
                'tin_validated' => true,
                'erca_tin_verified' => true,
                'erca_verified_at' => now(),
                'erca_name_status' => \App\Enums\ErcaNameStatus::Matched->value,
                'erca_last_checked_at' => now(),
                'erca_next_check_at' => now()->addHours(max(24, (int) config('services.etrade.recheck_hours', 168))),
                'erca_last_error' => null,
                'phone' => $claimPhone,
                'claim_phone' => $claimPhone,
                'erca_phone' => $ercaPhone !== '' ? $ercaPhone : null,
                'revenue_phone' => $claimPhone,
                'email' => $companyEmail,
                'address' => $companyAddress,
                'is_active' => true,
                'approval_status' => CompanyApprovalStatus::Approved,
                'approved_by_user_id' => null,
                'approved_at' => now(),
                'approval_note' => 'Auto-approved after partner ERCA TIN number consent.',
                'created_by_contact_id' => $contact->id,
            ]);

            $this->linkContact(
                $contact,
                $company,
                CompanyRole::Owner,
                switchTo: ! $keepApprovedContext,
            );

            $this->recordStatusHistory(
                $company,
                'approved',
                null,
                $contact,
                'Auto-approved after partner ERCA TIN number consent.',
                ['auto' => true, 'via' => 'erca'],
            );
            $this->recordStatusHistory(
                $company,
                'tin_validated',
                null,
                $contact,
                'TIN number confirmed via ERCA / eTrade',
                ['auto' => true, 'via' => 'erca'],
            );

            $fresh = $contact->fresh(['company', 'memberships.company']);
            $this->notifications->companyTinValidated($company->fresh() ?? $company);
            $this->notifications->companyProfileDecided($company->fresh() ?? $company, $fresh, approved: true);

            return [
                'contact' => $fresh->fresh(['company', 'memberships.company']),
                'company' => $company->fresh() ?? $company,
            ];
        });

        // Bring over alive subscriptions left on abandoned MVAS / unverified companies.
        app(RemountSubscriptionsToVerifiedTinService::class)
            ->remountForCompany($result['company'], dryRun: false);

        // Merge any leftover MVAS placeholder shell for this owner into the verified company.
        $consolidator = app(ConsolidateMvasIntoVerifiedTinService::class);
        foreach ($consolidator->discoverPairs() as $pair) {
            if ((int) $pair->new_id === (int) $result['company']->id) {
                $consolidator->consolidatePair((int) $pair->old_id, (int) $pair->new_id, dryRun: false);
            }
        }

        return $result['contact']->fresh(['company', 'memberships.company']);
    }

    /**
     * Update current company TIN number + name from an ERCA preview consent (portal mismatch fix).
     *
     * @param  array{company_tin: string, legal_name: string, company_phone?: ?string, company_email?: ?string, company_address?: ?string}  $data
     */
    public function updateCompanyFromErcaPreview(Contact $contact, array $data): Contact
    {
        if (! $contact->current_company_id || ! $contact->hasActiveCompanyMembership()) {
            throw ValidationException::withMessages([
                'company' => 'Link an active company before updating the TIN number.',
            ]);
        }

        $isOwner = $this->roleOf($contact) === CompanyRole::Owner;
        if (! $isOwner && ! $this->contactHasPermission($contact, CompanyMemberPermission::EditCompanyProfile)) {
            throw ValidationException::withMessages([
                'company_tin' => 'Only the company owner (or a member with edit permission) can update the TIN number.',
            ]);
        }

        $company = $contact->company;
        if (! $company) {
            throw ValidationException::withMessages(['company' => 'Company not found.']);
        }

        $this->assertErcaIdentityEditable($company);

        $tin = $this->normalizeEthiopianTin($data['company_tin']);
        $this->assertUniqueTin($tin, $company->id);

        // Preview consent path must have approved; re-assert live if somehow missing.
        if (! \App\Support\ErcaTinWriteGuard::isApproved($tin)) {
            $this->ercaTin->assertTinExistsInErca($tin);
        }

        $legal = \App\Services\Etrade\CompanyNameMatcher::titleCase(
            trim((string) ($data['legal_name'] ?? '')),
        );
        if ($legal === '') {
            throw ValidationException::withMessages([
                'legal_name' => 'ERCA legal name is required.',
            ]);
        }

        $wasValidated = (bool) $company->tin_validated;
        $tinChanged = (string) $company->tin !== $tin;

        // TIN change resets ERCA flags in model boot — apply ERCA-confirmed values afterwards.
        if ($tinChanged) {
            $company->fill(['tin' => $tin])->save();
            if ($wasValidated) {
                $this->recordStatusHistory($company, 'tin_cleared', null, $contact, 'Partner replaced TIN number via ERCA search');
            }
        }

        $updates = [
            'name' => $legal,
            'legal_name' => $legal,
            'tin' => $tin,
            'tin_validated' => true,
            'erca_tin_verified' => true,
            'erca_verified_at' => now(),
            'erca_name_status' => \App\Enums\ErcaNameStatus::Matched,
            'erca_last_checked_at' => now(),
            'erca_next_check_at' => now()->addHours(max(24, (int) config('services.etrade.recheck_hours', 168))),
            'erca_last_error' => null,
        ];

        $ercaPhone = trim((string) ($data['company_phone'] ?? ''));
        if ($ercaPhone !== '' && \App\Support\PhoneNumber::isValidLocalMobile($ercaPhone)) {
            $updates['erca_phone'] = \App\Support\PhoneNumber::normalize($ercaPhone);
        }

        $ercaEmail = trim((string) ($data['company_email'] ?? ''));
        if ($ercaEmail !== '' && filter_var($ercaEmail, FILTER_VALIDATE_EMAIL)) {
            $updates['email'] = strtolower($ercaEmail);
        }

        $ercaAddress = trim((string) ($data['company_address'] ?? ''));
        if ($ercaAddress !== '') {
            $updates['address'] = $ercaAddress;
        }

        $company->forceFill($updates)->save();

        $this->recordStatusHistory(
            $company,
            'tin_validated',
            null,
            $contact,
            'TIN number confirmed via ERCA search and partner consent',
            ['auto' => true, 'via' => 'erca_update'],
        );

        $this->ensureApprovedWhenTinValidated($company->fresh() ?? $company);
        $this->syncAllMembersDenormalizedFields($company->fresh() ?? $company);

        return $contact->fresh(['company', 'memberships.company']);
    }

    /**
     * Owner may edit company details only while awaiting (or after) admin rejection.
     * Once approved, only admin can update or remove company data in Filament.
     *
     * @param  array{company_name: string, company_tin: string, company_address: string}  $data
     */
    public function updateOwnCompany(Contact $contact, array $data): Contact
    {
        if (! $contact->current_company_id) {
            return $this->createCompanyForContact($contact, $data);
        }

        $company = $contact->company;
        if (! $company) {
            throw ValidationException::withMessages(['company' => 'Company not found.']);
        }

        if (! $this->contactHasPermission($contact, CompanyMemberPermission::EditCompanyProfile)) {
            throw ValidationException::withMessages([
                'company' => 'You do not have permission to update organisation details. Ask your company owner to grant access.',
            ]);
        }

        if (! $contact->hasActiveCompanyMembership()) {
            throw ValidationException::withMessages([
                'company' => 'Your membership for this company is disabled. Contact your company owner or an administrator.',
            ]);
        }

        if ($company->isApproved()) {
            throw ValidationException::withMessages([
                'company' => 'This company TIN number is already validated. Ask an administrator to update company details.',
            ]);
        }

        $phone = \App\Support\PhoneNumber::normalize((string) ($company->claimPhone() ?: $contact->phone_number));
        $email = trim((string) ($company->email ?: $contact->email));
        if ($phone === '' || ! \App\Support\PhoneNumber::isValidLocalMobile($phone)) {
            throw ValidationException::withMessages([
                'company' => 'A valid company phone is required. Use your existing company phone or sign in again.',
            ]);
        }

        $tin = $this->normalizeEthiopianTin($data['company_tin']);
        $name = trim($data['company_name']);

        if ($company->isErcaIdentityLocked()) {
            if ($name !== (string) $company->name || $tin !== (string) $company->tin) {
                $this->assertErcaIdentityEditable($company);
            }
            // Address-only update while name/TIN number stay frozen.
            $company->fill([
                'address' => trim($data['company_address']),
                'phone' => $phone,
                'claim_phone' => $phone,
                'email' => $email !== '' ? \App\Support\EmailAddress::normalize($email) : null,
            ])->save();
            $this->syncAllMembersDenormalizedFields($company);

            return $contact->fresh(['company', 'memberships.company']);
        }

        $this->assertUniqueTin($tin, $company->id);

        $previousTin = (string) $company->tin;
        $previousName = (string) $company->name;

        if ($previousTin !== $tin) {
            $this->ercaTin->assertTinExistsInErca($tin);
        }

        $fresh = DB::transaction(function () use ($contact, $company, $data, $tin, $name, $phone, $email) {
            $company->fill([
                'name' => $name,
                'tin' => $tin,
                'tin_validated' => false,
                'phone' => $phone,
                'claim_phone' => $phone,
                'revenue_phone' => $company->revenue_phone ?: $phone,
                'email' => $email !== '' ? \App\Support\EmailAddress::normalize($email) : null,
                'address' => trim($data['company_address']),
                'approval_status' => CompanyApprovalStatus::Pending,
                'approval_note' => null,
                'approved_by_user_id' => null,
                'approved_at' => null,
                'is_active' => false,
            ])->save();

            $this->syncAllMembersDenormalizedFields($company);

            $result = $contact->fresh(['company', 'memberships.company']);
            $this->notifications->companyProfileSubmitted($result, $company);
            $this->autoApproveOwnedCompaniesAfterIdentityVerification($result);

            return $result->fresh(['company', 'memberships.company']);
        });

        $updated = $company->fresh() ?? $company;
        if ($previousTin !== $tin) {
            $this->safeErcaVerify($updated, force: true);
        } elseif ($previousName !== (string) $updated->name) {
            $this->ercaTin->rematchEnteredName($updated);
        }

        return $fresh->fresh(['company', 'memberships.company']);
    }

    /**
     * Forces ERCA verification — admin cannot mark TIN number OK without ERCA.
     */
    public function approveCompany(Company $company, User $admin, ?string $note = null): Company
    {
        $this->assertCompanyReadyForApproval($company);

        return $this->markTinValidated($company, $admin);
    }

    /**
     * Identity verification no longer approves companies — TIN number validation does.
     * If TIN is already validated, ensure Active + synced approval_status.
     */
    public function autoApproveOwnedCompaniesAfterIdentityVerification(Contact $contact): void
    {
        if (! $contact->isIdentityVerified()) {
            return;
        }

        $contact->loadMissing(['memberships.company']);

        foreach ($contact->memberships as $membership) {
            if (! $membership->is_active) {
                continue;
            }
            $role = $membership->role instanceof CompanyRole
                ? $membership->role
                : CompanyRole::tryFrom((string) $membership->role);
            if ($role !== CompanyRole::Owner) {
                continue;
            }

            $company = $membership->company;
            if (! $company || ! $company->isTinValidated()) {
                continue;
            }

            $this->ensureApprovedWhenTinValidated($company);
        }
    }

    /**
     * @throws ValidationException
     */
    protected function assertCompanyReadyForApproval(Company $company): void
    {
        $required = [
            'name' => $company->name,
            'tin' => $company->tin,
            'phone' => $company->claimPhone(),
            'email' => $company->email,
            'address' => $company->address,
        ];
        foreach ($required as $field => $value) {
            if (! filled($value)) {
                throw ValidationException::withMessages([
                    $field => 'Company '.$field.' is required before approval.',
                ]);
            }
        }

        if (! TinNumber::isValid($company->tin)) {
            throw ValidationException::withMessages([
                'tin' => TinNumber::message().' Ask the partner to enter a valid TIN number before approval.',
            ]);
        }

        if (! $company->hasOwner()) {
            throw ValidationException::withMessages([
                'owner' => 'Company must have an owner (the partner who created the profile) before approval.',
            ]);
        }
    }

    public function rejectCompany(Company $company, User $admin, ?string $note = null): Company
    {
        // Deactivate instead of a separate "profile rejected" gate — TIN + Active are the flags.
        $note = filled($note) ? trim($note) : 'Company deactivated by admin.';

        $company->fill([
            'approval_status' => CompanyApprovalStatus::Pending,
            'approval_note' => $note,
            'is_active' => false,
            'tin_validated' => false,
        ])->save();

        $this->recordStatusHistory($company, 'rejected', $admin, null, $note);
        $this->revokePortalAccessForInactiveCompany($company);

        return $company->fresh(['memberships', 'approvedBy']) ?? $company;
    }

    public function lookupByIdentity(string $tin): ?Company
    {
        $tin = TinNumber::normalize($tin);
        if ($tin === '' || ! TinNumber::isValid($tin)) {
            return null;
        }

        return Company::query()
            ->where('tin', $tin)
            ->where('is_active', true)
            ->tinApproved()
            ->first();
    }

    /** @deprecated Use lookupByIdentity */
    public function lookupByTin(string $tin): ?Company
    {
        return $this->lookupByIdentity($tin);
    }

    public function requestAttach(
        Contact $contact,
        string $tin,
        ?string $note = null,
    ): CompanyChangeRequest {
        if ($this->pendingRequestFor($contact)) {
            throw ValidationException::withMessages([
                'company_tin' => 'You already have a pending company request.',
            ]);
        }

        $company = $this->lookupByIdentity($tin);
        if (! $company) {
            throw ValidationException::withMessages([
                'company_tin' => 'No active company with a validated TIN number found. Create a new company instead.',
            ]);
        }

        if ($this->membershipFor($contact, $company)) {
            throw ValidationException::withMessages([
                'company_tin' => 'You are already a member of this company. Switch to it in the portal.',
            ]);
        }

        if (! $company->hasOwner()) {
            throw ValidationException::withMessages([
                'company_tin' => 'This company has no owner yet, so membership cannot be requested.',
            ]);
        }

        $request = CompanyChangeRequest::query()->create([
            'contact_id' => $contact->id,
            'company_id' => $company->id,
            'type' => CompanyChangeType::Attach,
            'status' => CompanyChangeStatus::Pending,
            'contact_note' => filled($note) ? trim($note) : null,
        ]);

        $this->notifications->companyChangeRequested($request);

        return $request->load(['company', 'contact']);
    }

    /**
     * Personal leave: partner detaches themselves from the current company immediately.
     * No admin approval or PDFs. Joining still requires company-owner approval.
     */
    public function leaveCompany(Contact $contact, ?string $note = null): Contact
    {
        if (! $contact->current_company_id) {
            throw ValidationException::withMessages([
                'company' => 'Select a company context before leaving.',
            ]);
        }

        if (! $contact->hasActiveCompanyMembership()) {
            throw ValidationException::withMessages([
                'company' => 'Your membership for this company is disabled. Contact an administrator.',
            ]);
        }

        if ($this->pendingRequestFor($contact)) {
            throw ValidationException::withMessages([
                'company' => 'You have a pending membership request. Wait for a decision or cancel it first.',
            ]);
        }

        $this->assertOwnerMayLeave($contact);

        $company = $contact->company;
        if (! $company) {
            throw ValidationException::withMessages(['company' => 'Company not found.']);
        }

        $owner = $company->ownerContact();
        $companyId = $company->id;

        return DB::transaction(function () use ($contact, $company, $companyId, $owner, $note) {
            CompanyChangeRequest::query()->create([
                'contact_id' => $contact->id,
                'company_id' => $companyId,
                'type' => CompanyChangeType::Detach,
                'status' => CompanyChangeStatus::Approved,
                'contact_note' => filled($note) ? trim($note) : null,
                'admin_note' => 'Personal leave — no admin approval required.',
                'reviewed_by_contact_id' => $contact->id,
                'reviewed_at' => now(),
            ]);

            $this->unlinkContact($contact, $company);

            if ($owner && (int) $owner->id !== (int) $contact->id) {
                DB::afterCommit(function () use ($company, $owner, $contact, $note) {
                    $this->notifications->memberLeftCompany(
                        $company->fresh(),
                        $owner->fresh(),
                        $contact->fresh(),
                        $note,
                    );
                });
            }

            return $contact->fresh(['company', 'memberships.company']);
        });
    }

    public function approve(CompanyChangeRequest $request, User|Contact $actor, ?string $adminNote = null): CompanyChangeRequest
    {
        if ($request->status !== CompanyChangeStatus::Pending) {
            throw ValidationException::withMessages(['status' => 'This request was already decided.']);
        }

        if ($request->type === CompanyChangeType::Detach) {
            throw ValidationException::withMessages([
                'status' => 'Leaving a company is personal and immediate. Partners detach themselves in the portal — no approval is needed.',
            ]);
        }

        if ($request->type === CompanyChangeType::TransferOwnership) {
            if (! $actor instanceof User) {
                throw ValidationException::withMessages([
                    'status' => 'Ownership transfer must be approved by an administrator.',
                ]);
            }

            return $this->approveOwnershipTransfer($request, $actor, $adminNote);
        }

        if ($request->type === CompanyChangeType::Attach && $actor instanceof User) {
            throw ValidationException::withMessages([
                'status' => 'Membership (attach) requests must be approved by the company owner in the partner portal.',
            ]);
        }

        if ($actor instanceof Contact) {
            $this->assertOwnerMayReview($actor, $request);
            if ($request->type !== CompanyChangeType::Attach) {
                throw ValidationException::withMessages([
                    'status' => 'Only membership (attach) requests can be decided by the company owner.',
                ]);
            }
        }

        return DB::transaction(function () use ($request, $actor, $adminNote) {
            $request->loadMissing(['contact', 'company']);
            $contact = $request->contact;
            $company = $request->company;

            if ($this->membershipFor($contact, $company)) {
                throw ValidationException::withMessages([
                    'status' => 'Contact is already a member of this company.',
                ]);
            }
            if (! $company->hasOwner()) {
                throw ValidationException::withMessages([
                    'status' => 'This company has no owner. Attach cannot be approved until an owner exists.',
                ]);
            }
            $this->linkContact($contact, $company, CompanyRole::Member, switchTo: false);

            $request->fill([
                'status' => CompanyChangeStatus::Approved,
                'admin_note' => filled($adminNote) ? trim($adminNote) : null,
                'reviewed_by_user_id' => $actor instanceof User ? $actor->id : null,
                'reviewed_by_contact_id' => $actor instanceof Contact ? $actor->id : null,
                'reviewed_at' => now(),
            ])->save();

            $this->notifications->companyChangeDecided($request->fresh(['contact', 'company']));

            return $request->fresh(['contact', 'company', 'reviewer', 'contactReviewer']);
        });
    }

    public function reject(CompanyChangeRequest $request, User|Contact $actor, ?string $adminNote = null): CompanyChangeRequest
    {
        if ($request->status !== CompanyChangeStatus::Pending) {
            throw ValidationException::withMessages(['status' => 'This request was already decided.']);
        }

        if ($request->type === CompanyChangeType::Detach) {
            throw ValidationException::withMessages([
                'status' => 'Leaving a company is personal and immediate. Partners detach themselves in the portal — no approval is needed.',
            ]);
        }

        if ($request->type === CompanyChangeType::TransferOwnership) {
            if (! $actor instanceof User) {
                throw ValidationException::withMessages([
                    'status' => 'Ownership transfer must be rejected by an administrator.',
                ]);
            }
        } elseif ($request->type === CompanyChangeType::Attach && $actor instanceof User) {
            throw ValidationException::withMessages([
                'status' => 'Membership (attach) requests must be rejected by the company owner in the partner portal.',
            ]);
        }

        if ($actor instanceof Contact) {
            $this->assertOwnerMayReview($actor, $request);
            if ($request->type !== CompanyChangeType::Attach) {
                throw ValidationException::withMessages([
                    'status' => 'Only membership (attach) requests can be decided by the company owner.',
                ]);
            }
        }

        $request->fill([
            'status' => CompanyChangeStatus::Rejected,
            'admin_note' => filled($adminNote) ? trim($adminNote) : null,
            'reviewed_by_user_id' => $actor instanceof User ? $actor->id : null,
            'reviewed_by_contact_id' => $actor instanceof Contact ? $actor->id : null,
            'reviewed_at' => now(),
        ])->save();

        $this->notifications->companyChangeDecided($request->fresh(['contact', 'company', 'targetContact']));

        return $request->fresh(['contact', 'company', 'reviewer', 'contactReviewer', 'targetContact']);
    }

    /**
     * Owner requests to transfer ownership to another active member (letter PDF required).
     * Admin must approve in Filament.
     */
    public function requestOwnershipTransfer(
        Contact $owner,
        string $newOwnerPublicId,
        UploadedFile $letter,
        ?string $note = null,
    ): CompanyChangeRequest {
        $this->assertIsActiveOwner($owner);

        if ($this->pendingRequestFor($owner)) {
            throw ValidationException::withMessages([
                'company' => 'You already have a pending company request.',
            ]);
        }

        $company = $owner->company;
        if (! $company?->isApproved()) {
            throw ValidationException::withMessages([
                'company' => 'Ownership can only be transferred after the company TIN number is validated.',
            ]);
        }

        $newOwner = Contact::query()->where('public_id', $newOwnerPublicId)->first();
        if (! $newOwner) {
            throw ValidationException::withMessages([
                'target_contact' => 'Selected partner was not found.',
            ]);
        }

        if ((int) $newOwner->id === (int) $owner->id) {
            throw ValidationException::withMessages([
                'target_contact' => 'Choose a different partner as the new owner.',
            ]);
        }

        $membership = $this->membershipFor($newOwner, $company);
        if (! $membership || ! $membership->is_active) {
            throw ValidationException::withMessages([
                'target_contact' => 'The new owner must be an active member of this company.',
            ]);
        }

        $letterMeta = $this->storePdf($owner, $letter, 'letter');

        $request = CompanyChangeRequest::query()->create([
            'contact_id' => $owner->id,
            'company_id' => $company->id,
            'target_contact_id' => $newOwner->id,
            'type' => CompanyChangeType::TransferOwnership,
            'status' => CompanyChangeStatus::Pending,
            'contact_note' => filled($note) ? trim($note) : null,
            'letter_disk' => $letterMeta['disk'],
            'letter_path' => $letterMeta['path'],
            'letter_original_name' => $letterMeta['original_name'],
            'letter_size_bytes' => $letterMeta['size'],
        ]);

        $this->notifications->companyChangeRequested($request->load(['company', 'contact', 'targetContact']));

        return $request;
    }

    protected function approveOwnershipTransfer(CompanyChangeRequest $request, User $admin, ?string $adminNote = null): CompanyChangeRequest
    {
        return DB::transaction(function () use ($request, $admin, $adminNote) {
            $request->loadMissing(['contact', 'company', 'targetContact']);
            $company = $request->company;
            $currentOwner = $request->contact;
            $newOwner = $request->targetContact;

            if (! $company || ! $currentOwner || ! $newOwner) {
                throw ValidationException::withMessages(['status' => 'Transfer request is incomplete.']);
            }

            $ownerMembership = CompanyMembership::query()
                ->where('company_id', $company->id)
                ->where('contact_id', $currentOwner->id)
                ->where('role', CompanyRole::Owner->value)
                ->first();

            if (! $ownerMembership) {
                throw ValidationException::withMessages([
                    'status' => 'Requester is no longer the company owner.',
                ]);
            }

            $this->transferOwnership($company, $newOwner, $admin);

            $request->fill([
                'status' => CompanyChangeStatus::Approved,
                'admin_note' => filled($adminNote) ? trim($adminNote) : null,
                'reviewed_by_user_id' => $admin->id,
                'reviewed_by_contact_id' => null,
                'reviewed_at' => now(),
            ])->save();

            $this->notifications->companyChangeDecided($request->fresh(['contact', 'company', 'targetContact']));

            return $request->fresh(['contact', 'company', 'reviewer', 'targetContact']);
        });
    }

    /**
     * Members of the current company (Fayda identity fields for the portal roster).
     * Any active member of an approved company may view the list.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    public function listCurrentCompanyMembers(Contact $viewer)
    {
        if (! $viewer->current_company_id) {
            throw ValidationException::withMessages([
                'company' => 'Link a company before viewing members.',
            ]);
        }

        if (! $viewer->hasActiveCompanyMembership()) {
            throw ValidationException::withMessages([
                'company' => 'Your membership for this company is disabled. Contact an administrator.',
            ]);
        }

        return CompanyMembership::query()
            ->with('contact')
            ->where('company_id', $viewer->current_company_id)
            ->orderByRaw("CASE WHEN role = 'owner' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->get()
            ->map(function (CompanyMembership $m) {
                $c = $m->contact;
                $role = $m->role instanceof CompanyRole ? $m->role->value : (string) $m->role;

                return [
                    'public_id' => $c?->public_id,
                    'name' => $c?->name,
                    'phone_number' => $c?->phone_number,
                    'email' => $c?->email,
                    'gender' => $c?->gender,
                    'nationality' => $c?->nationality,
                    'birthdate' => optional($c?->birthdate)?->toDateString() ?? $c?->birthdate,
                    'identification_type' => $c?->identification_type,
                    'identification_number' => $c?->identification_number,
                    'role' => $role,
                    'is_active' => (bool) $m->is_active,
                    'is_owner' => $role === CompanyRole::Owner->value,
                    'awaiting_fayda' => $c ? $this->isPlaceholderContact($c) : false,
                    'identity_verified' => $c ? $c->isIdentityVerified() : false,
                    'identity_verified_via' => $c?->identityVerifiedViaValue(),
                    'fayda_verified' => (bool) ($c?->fayda_verified),
                    'permissions' => $this->effectivePermissionsForMembership($m),
                ];
            })
            ->values();
    }

    /**
     * Owner enables or disables a member of the current company.
     */
    public function setMembershipActiveByOwner(Contact $actor, Contact $member, bool $active): Contact
    {
        $this->assertIsActiveOwner($actor);

        $company = Company::query()->findOrFail((int) $actor->current_company_id);
        if ((int) $member->id === (int) $actor->id) {
            throw ValidationException::withMessages([
                'member' => 'You cannot change your own access. Transfer ownership first if needed.',
            ]);
        }

        return $this->setMembershipActive($company, $member, $active, $actor);
    }

    /**
     * Owner corrects a non-owner member's phone (unique across contacts).
     * Invite placeholders also refresh their invite Fayda sub so the new phone can sync.
     */
    public function updateMemberPhoneByOwner(Contact $actor, Contact $member, string $phoneNumber): Contact
    {
        $this->assertIsActiveOwner($actor);

        $company = Company::query()->findOrFail((int) $actor->current_company_id);
        if ((int) $member->id === (int) $actor->id) {
            throw ValidationException::withMessages([
                'phone_number' => 'You cannot change your own phone here. Update it via Fayda sign-in or ask an administrator.',
            ]);
        }

        $membership = $this->membershipFor($member, $company);
        if (! $membership) {
            throw ValidationException::withMessages([
                'member' => 'This partner is not a member of this company.',
            ]);
        }

        if ($membership->isOwner()) {
            throw ValidationException::withMessages([
                'phone_number' => 'The company owner phone cannot be changed here.',
            ]);
        }

        $phone = \App\Support\PhoneNumber::normalize($phoneNumber);
        if ($phone === '' || ! \App\Support\PhoneNumber::isValidLocalMobile($phone)) {
            throw ValidationException::withMessages([
                'phone_number' => 'Enter a valid Ethiopian mobile number (last 9 digits).',
            ]);
        }

        $current = \App\Support\PhoneNumber::normalize((string) $member->phone_number);
        if ($phone === $current) {
            return $member->fresh(['company', 'memberships.company']) ?? $member;
        }

        $conflict = Contact::query()
            ->where('phone_number', $phone)
            ->where('id', '!=', $member->id)
            ->exists();
        if ($conflict) {
            throw ValidationException::withMessages([
                'phone_number' => 'This phone already belongs to another partner. Add that partner to this company instead, or ask an administrator.',
            ]);
        }

        if ($this->isPlaceholderContact($member)) {
            $inviteSub = 'invite-phone-'.$phone;
            $member->syncFromFayda([
                'sub' => $inviteSub,
                'name' => $member->name,
                'phone_number' => $phone,
                'email' => $member->email,
                'gender' => $member->gender,
                'nationality' => $member->nationality,
                'identification_type' => $member->identification_type ?: '2',
                'identification_number' => $inviteSub,
                'birthdate' => $member->birthdate,
                'address' => $member->address,
            ]);
        } else {
            $member->updateFromAdmin([
                'phone_number' => $phone,
            ]);
        }

        Log::info('Owner updated company member phone', [
            'company_id' => $company->id,
            'owner_id' => $actor->id,
            'member_contact_id' => $member->id,
            'phone' => $phone,
            'placeholder' => $this->isPlaceholderContact($member->fresh() ?? $member),
        ]);

        return $member->fresh(['company', 'memberships.company']) ?? $member;
    }

    /**
     * Owner grants portal permissions to a non-owner member.
     *
     * @param  list<string>  $permissions
     */
    public function updateMemberPermissionsByOwner(Contact $actor, Contact $member, array $permissions): Contact
    {
        $this->assertIsActiveOwner($actor);

        $company = Company::query()->findOrFail((int) $actor->current_company_id);
        $membership = $this->membershipFor($member, $company);
        if (! $membership) {
            throw ValidationException::withMessages([
                'member' => 'This partner is not a member of this company.',
            ]);
        }

        if ($membership->isOwner()) {
            throw ValidationException::withMessages([
                'permissions' => 'Company owners always have full permissions.',
            ]);
        }

        $allowed = CompanyMemberPermission::allValues();
        $normalized = collect(CompanyMemberPermission::normalizeStored($permissions))
            ->unique()
            ->filter(fn (string $p) => in_array($p, $allowed, true))
            ->values()
            ->all();

        $membership->forceFill(['permissions' => $normalized])->save();

        return $member->fresh(['company', 'memberships.company']);
    }

    public function findCurrentCompanyMemberByPublicId(Contact $viewer, string $publicId): Contact
    {
        if (! $viewer->current_company_id) {
            throw ValidationException::withMessages([
                'company' => 'Link a company before managing members.',
            ]);
        }

        $member = Contact::query()->where('public_id', $publicId)->first();
        if (! $member) {
            throw ValidationException::withMessages([
                'member' => 'Member not found.',
            ]);
        }

        $membership = $this->membershipFor($member, Company::query()->findOrFail((int) $viewer->current_company_id));
        if (! $membership) {
            throw ValidationException::withMessages([
                'member' => 'This partner is not a member of this company.',
            ]);
        }

        return $member;
    }

    public function assertHasPermission(Contact $contact, CompanyMemberPermission $permission): void
    {
        $this->assertCanAccessCompany($contact);

        if (! $this->contactHasPermission($contact, $permission)) {
            throw ValidationException::withMessages([
                'permission' => match ($permission) {
                    CompanyMemberPermission::CreateSubscriptions => 'You do not have permission to start new VAS subscriptions. Ask your company owner to grant access.',
                    CompanyMemberPermission::ManageServices => 'You do not have permission to manage services. Ask your company owner to grant access.',
                    CompanyMemberPermission::ManageMembershipRequests => 'You do not have permission to manage membership requests. Ask your company owner to grant access.',
                    CompanyMemberPermission::EditCompanyProfile => 'You do not have permission to edit the company profile. Ask your company owner to grant access.',
                },
            ]);
        }
    }

    public function contactHasPermission(Contact $contact, CompanyMemberPermission $permission): bool
    {
        $membership = $contact->membershipForCurrentCompany();
        if (! $membership || ! $membership->is_active) {
            return false;
        }

        return in_array($permission->value, $this->effectivePermissionsForMembership($membership), true);
    }

    /**
     * @return list<string>
     */
    public function effectivePermissionsForMembership(CompanyMembership $membership): array
    {
        if ($membership->isOwner()) {
            return CompanyMemberPermission::allValues();
        }

        $stored = $membership->permissions;
        if (! is_array($stored)) {
            return CompanyMemberPermission::defaultsForMember();
        }

        $allowed = CompanyMemberPermission::allValues();

        return collect(CompanyMemberPermission::normalizeStored($stored))
            ->filter(fn (string $p) => in_array($p, $allowed, true))
            ->values()
            ->all();
    }

    /**
     * Pending attach requests waiting for this company owner.
     *
     * @return \Illuminate\Support\Collection<int, CompanyChangeRequest>
     */
    public function pendingMembershipRequestsForOwner(Contact $owner)
    {
        $this->assertCanManageMembershipRequests($owner);

        return CompanyChangeRequest::query()
            ->with(['contact', 'company'])
            ->where('company_id', $owner->current_company_id)
            ->where('type', CompanyChangeType::Attach)
            ->where('status', CompanyChangeStatus::Pending)
            ->latest('id')
            ->get();
    }

    /**
     * Shared inbox: requests this partner submitted + membership joins they must review as owner.
     *
     * @return array{submitted: list<array<string, mixed>>, to_review: list<array<string, mixed>>, summary: array<string, int>}
     */
    public function companyRequestsInbox(Contact $contact): array
    {
        $reviewCompanyIds = CompanyMembership::query()
            ->where('contact_id', $contact->id)
            ->where('is_active', true)
            ->get()
            ->filter(function (CompanyMembership $m) {
                return $m->isOwner()
                    || in_array(
                        CompanyMemberPermission::ManageMembershipRequests->value,
                        $this->effectivePermissionsForMembership($m),
                        true,
                    );
            })
            ->pluck('company_id')
            ->values();

        $ownedCompanyIds = CompanyMembership::query()
            ->where('contact_id', $contact->id)
            ->where('role', CompanyRole::Owner->value)
            ->where('is_active', true)
            ->pluck('company_id');

        $submittedChanges = CompanyChangeRequest::query()
            ->with(['contact', 'company', 'targetContact', 'reviewer', 'contactReviewer'])
            ->where('contact_id', $contact->id)
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (CompanyChangeRequest $r) => $this->serializeRequestCard($r, $contact, 'submitted'))
            ->all();

        $profileCards = Company::query()
            ->where(function ($q) use ($contact, $ownedCompanyIds) {
                $q->where('created_by_contact_id', $contact->id);
                if ($ownedCompanyIds->isNotEmpty()) {
                    $q->orWhereIn('id', $ownedCompanyIds);
                }
            })
            ->whereIn('approval_status', [
                CompanyApprovalStatus::Pending->value,
                CompanyApprovalStatus::Rejected->value,
            ])
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn (Company $company) => $this->serializeCompanyProfileCard($company))
            ->all();

        $submitted = array_values(array_merge($profileCards, $submittedChanges));
        usort($submitted, function (array $a, array $b): int {
            return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
        });

        $toReview = [];
        if ($reviewCompanyIds->isNotEmpty()) {
            $toReview = CompanyChangeRequest::query()
                ->with(['contact', 'company', 'targetContact', 'reviewer', 'contactReviewer'])
                ->whereIn('company_id', $reviewCompanyIds)
                ->where('type', CompanyChangeType::Attach)
                ->where('status', CompanyChangeStatus::Pending)
                ->latest('id')
                ->limit(50)
                ->get()
                ->map(fn (CompanyChangeRequest $r) => $this->serializeRequestCard($r, $contact, 'to_review'))
                ->all();
        }

        return [
            'submitted' => $submitted,
            'to_review' => $toReview,
            'summary' => [
                'submitted_pending' => count(array_filter(
                    $submitted,
                    fn (array $row) => in_array(($row['status'] ?? ''), [
                        CompanyChangeStatus::Pending->value,
                        CompanyApprovalStatus::Pending->value,
                    ], true),
                )),
                'to_review_pending' => count($toReview),
            ],
        ];
    }

    public function cancelOwnRequest(Contact $contact, CompanyChangeRequest $request): CompanyChangeRequest
    {
        if ((int) $request->contact_id !== (int) $contact->id) {
            throw ValidationException::withMessages([
                'request' => 'You can only cancel your own requests.',
            ]);
        }

        if ($request->status !== CompanyChangeStatus::Pending) {
            throw ValidationException::withMessages([
                'request' => 'Only pending requests can be cancelled.',
            ]);
        }

        if ($request->type === CompanyChangeType::Detach) {
            throw ValidationException::withMessages([
                'request' => 'Leave requests are applied immediately and cannot be cancelled.',
            ]);
        }

        $request->fill([
            'status' => CompanyChangeStatus::Rejected,
            'admin_note' => 'Cancelled by requester.',
            'reviewed_by_contact_id' => $contact->id,
            'reviewed_by_user_id' => null,
            'reviewed_at' => now(),
        ])->save();

        return $request->fresh(['contact', 'company', 'targetContact']);
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeRequestCard(
        CompanyChangeRequest $request,
        ?Contact $viewer = null,
        string $direction = 'submitted',
    ): array {
        $type = $request->type instanceof CompanyChangeType
            ? $request->type->value
            : (string) $request->type;
        $status = $request->status instanceof CompanyChangeStatus
            ? $request->status->value
            : (string) $request->status;

        $awaiting = match (true) {
            $status !== CompanyChangeStatus::Pending->value => 'none',
            $type === CompanyChangeType::Attach->value => 'company_owner',
            $type === CompanyChangeType::TransferOwnership->value => 'admin',
            default => 'admin',
        };

        $canDecide = $viewer
            && $direction === 'to_review'
            && $status === CompanyChangeStatus::Pending->value
            && $type === CompanyChangeType::Attach->value
            && $this->viewerCanDecideMembershipForCompany($viewer, (int) $request->company_id);

        $canCancel = $viewer
            && $direction === 'submitted'
            && $status === CompanyChangeStatus::Pending->value
            && (int) $request->contact_id === (int) $viewer->id
            && $type !== CompanyChangeType::Detach->value;

        return [
            'kind' => 'membership_change',
            'public_id' => $request->public_id,
            'type' => $type,
            'status' => $status,
            'direction' => $direction,
            'awaiting' => $awaiting,
            'contact_note' => $request->contact_note,
            'decision_note' => $request->admin_note,
            'decided_by' => $request->decidedByLabel(),
            'created_at' => optional($request->created_at)?->toIso8601String(),
            'reviewed_at' => optional($request->reviewed_at)?->toIso8601String(),
            'can_approve' => $canDecide,
            'can_reject' => $canDecide,
            'can_cancel' => $canCancel,
            'has_proposal' => $request->hasProposal(),
            'has_letter' => $request->hasLetter(),
            'company' => $request->company ? [
                'public_id' => $request->company->public_id,
                'name' => $request->company->name,
                'tin' => $request->company->tin,
            ] : null,
            'applicant' => $request->contact ? [
                'public_id' => $request->contact->public_id,
                'name' => $request->contact->name,
                'phone_number' => $request->contact->phone_number,
                'email' => $request->contact->email,
            ] : null,
            'target_contact' => $request->targetContact ? [
                'public_id' => $request->targetContact->public_id,
                'name' => $request->targetContact->name,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeCompanyProfileCard(Company $company): array
    {
        $status = $company->approval_status instanceof CompanyApprovalStatus
            ? $company->approval_status->value
            : (string) $company->approval_status;

        return [
            'kind' => 'company_profile',
            'public_id' => $company->public_id,
            'type' => 'company_profile',
            'status' => $status,
            'direction' => 'submitted',
            'awaiting' => $status === CompanyApprovalStatus::Pending->value ? 'admin' : 'none',
            'contact_note' => null,
            'decision_note' => $company->approval_note,
            'decided_by' => $status === CompanyApprovalStatus::Pending->value ? '—' : 'admin',
            'created_at' => optional($company->created_at)?->toIso8601String(),
            'reviewed_at' => optional($company->approved_at)?->toIso8601String(),
            'can_approve' => false,
            'can_reject' => false,
            'can_cancel' => false,
            'has_proposal' => false,
            'has_letter' => false,
            'company' => [
                'public_id' => $company->public_id,
                'name' => $company->name,
                'tin' => $company->tin,
            ],
            'applicant' => null,
            'target_contact' => null,
        ];
    }

    protected function contactOwnsCompany(Contact $contact, int $companyId): bool
    {
        return CompanyMembership::query()
            ->where('contact_id', $contact->id)
            ->where('company_id', $companyId)
            ->where('role', CompanyRole::Owner->value)
            ->where('is_active', true)
            ->exists();
    }

    protected function viewerCanDecideMembershipForCompany(Contact $viewer, int $companyId): bool
    {
        $membership = CompanyMembership::query()
            ->where('contact_id', $viewer->id)
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->first();

        if (! $membership) {
            return false;
        }

        return in_array(
            CompanyMemberPermission::ManageMembershipRequests->value,
            $this->effectivePermissionsForMembership($membership),
            true,
        );
    }

    protected function assertOwnerMayReview(Contact $actor, CompanyChangeRequest $request): void
    {
        if ((int) $actor->current_company_id !== (int) $request->company_id
            || ! $actor->hasActiveCompanyMembership()) {
            throw ValidationException::withMessages([
                'status' => 'Switch to this company with an active membership before deciding requests.',
            ]);
        }

        if (! $this->contactHasPermission($actor, CompanyMemberPermission::ManageMembershipRequests)) {
            throw ValidationException::withMessages([
                'status' => 'Only the company owner (or a member granted membership approval) can decide this request.',
            ]);
        }

        $company = $request->relationLoaded('company')
            ? $request->company
            : Company::query()->find($request->company_id);

        if (! $company?->isApproved()) {
            throw ValidationException::withMessages([
                'company' => 'Membership requests are available after the company TIN number is validated.',
            ]);
        }
    }

    protected function assertIsActiveOwner(Contact $contact): void
    {
        if ($this->roleOf($contact) !== CompanyRole::Owner || ! $contact->current_company_id) {
            throw ValidationException::withMessages([
                'company' => 'Only the company owner can manage members.',
            ]);
        }

        if (! $contact->hasActiveCompanyMembership()) {
            throw ValidationException::withMessages([
                'company' => 'Your membership for this company is disabled.',
            ]);
        }

        $contact->loadMissing('company');
        if (! $contact->company?->isApproved()) {
            throw ValidationException::withMessages([
                'company' => 'Member management is available after the company TIN number is validated.',
            ]);
        }
    }

    protected function assertCanManageMembershipRequests(Contact $contact): void
    {
        if (! $contact->current_company_id || ! $contact->hasActiveCompanyMembership()) {
            throw ValidationException::withMessages([
                'company' => 'Your membership for this company is disabled.',
            ]);
        }

        $contact->loadMissing('company');
        if (! $contact->company?->isApproved()) {
            throw ValidationException::withMessages([
                'company' => 'Membership requests are available after the company TIN number is validated.',
            ]);
        }

        if (! $this->contactHasPermission($contact, CompanyMemberPermission::ManageMembershipRequests)) {
            throw ValidationException::withMessages([
                'company' => 'Only the company owner (or a member granted membership approval) can manage membership requests.',
            ]);
        }
    }

    public function pendingRequestFor(Contact $contact): ?CompanyChangeRequest
    {
        return CompanyChangeRequest::query()
            ->with('company')
            ->where('contact_id', $contact->id)
            ->where('status', CompanyChangeStatus::Pending)
            ->latest('id')
            ->first();
    }

    public function linkContact(
        Contact $contact,
        Company $company,
        CompanyRole $role,
        bool $switchTo = true,
        bool $isActive = true,
    ): void
    {
        if ($role === CompanyRole::Owner) {
            $existingOwnerId = CompanyMembership::query()
                ->where('company_id', $company->id)
                ->where('role', CompanyRole::Owner->value)
                ->value('contact_id');
            if ($existingOwnerId && (int) $existingOwnerId !== (int) $contact->id) {
                throw ValidationException::withMessages([
                    'company' => 'This company already has an owner. Transfer ownership first.',
                ]);
            }
        } else {
            if (! $company->hasOwner()) {
                throw ValidationException::withMessages([
                    'company' => 'A company must have an owner before members can join.',
                ]);
            }
        }

        CompanyMembership::query()->updateOrCreate(
            [
                'contact_id' => $contact->id,
                'company_id' => $company->id,
            ],
            [
                'role' => $role->value,
                'is_active' => $isActive,
                'permissions' => $role === CompanyRole::Owner
                    ? null
                    : CompanyMemberPermission::defaultsForMember(),
            ],
        );

        if ($isActive && ($switchTo || ! $contact->current_company_id)) {
            $contact->forceFill([
                'current_company_id' => $company->id,
                'profile_completed_at' => now(),
            ]);
            $this->syncContactCompanyFields($contact, $company);
        } elseif ($isActive) {
            $contact->forceFill(['profile_completed_at' => $contact->profile_completed_at ?? now()])->save();
        } else {
            // Keep inactive membership out of portal context.
            if ((int) $contact->current_company_id === (int) $company->id) {
                $this->switchToFallbackCompany($contact, exceptCompanyId: $company->id);
            } else {
                $contact->forceFill(['profile_completed_at' => $contact->profile_completed_at ?? now()])->save();
            }
        }
    }

    /**
     * Company owner pre-creates a member. When that person signs in with Fayda
     * (same phone), identity syncs onto this contact. Portal access only works
     * if this membership stays active.
     *
     * @param  array{name: string, phone_number: string, email?: string|null, is_active?: bool}  $data
     * @return array{member: array<string, mixed>, contact: Contact}
     */
    public function createMemberByOwner(Contact $actor, array $data): array
    {
        $this->assertIsActiveOwner($actor);

        $company = Company::query()->findOrFail((int) $actor->current_company_id);
        if (! $company->isApproved()) {
            throw ValidationException::withMessages([
                'company' => 'Add members after admin approves this company profile.',
            ]);
        }

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw ValidationException::withMessages([
                'name' => 'Member name is required.',
            ]);
        }

        $phone = \App\Support\PhoneNumber::normalize((string) ($data['phone_number'] ?? ''));
        if ($phone === '' || ! \App\Support\PhoneNumber::isValidLocalMobile($phone)) {
            throw ValidationException::withMessages([
                'phone_number' => 'Enter a valid Ethiopian mobile number (last 9 digits).',
            ]);
        }

        $email = isset($data['email']) ? trim((string) $data['email']) : '';
        $email = \App\Support\EmailAddress::normalize($email !== '' ? $email : null);
        // New members are always active — owner can disable later. No invite OTP.
        $isActive = true;

        if ($email) {
            $emailTaken = Contact::query()
                ->where('email', $email)
                ->where(function ($q) use ($phone) {
                    $q->whereNull('phone_number')->orWhere('phone_number', '!=', $phone);
                })
                ->exists();
            if ($emailTaken) {
                throw ValidationException::withMessages([
                    'email' => 'This email is already used by another partner.',
                ]);
            }
        }

        $result = DB::transaction(function () use ($actor, $company, $name, $phone, $email, $isActive) {
            $contact = Contact::query()->where('phone_number', $phone)->first();
            $linkedExisting = (bool) $contact;

            if ($contact && (int) $contact->id === (int) $actor->id) {
                throw ValidationException::withMessages([
                    'phone_number' => 'You cannot add yourself as a member.',
                ]);
            }

            if ($contact && ! $contact->is_active) {
                throw ValidationException::withMessages([
                    'phone_number' => 'This partner is inactive and cannot be added.',
                ]);
            }

            if ($contact && $this->membershipFor($contact, $company)) {
                throw ValidationException::withMessages([
                    'phone_number' => 'This phone number is already a member of this company.',
                ]);
            }

            if (! $contact) {
                $inviteSub = 'invite-phone-'.$phone;
                $contact = new Contact;
                $contact->syncFromFayda([
                    'sub' => $inviteSub,
                    'name' => $name,
                    'phone_number' => $phone,
                    'email' => $email,
                    'identification_type' => '2',
                    'identification_number' => $inviteSub,
                ]);
                $contact->forceFill([
                    'is_active' => true,
                    'current_company_id' => null,
                    'profile_completed_at' => null,
                ])->save();
            } else {
                // Refresh display name/email on invite placeholders only.
                if ($this->isPlaceholderContact($contact)) {
                    $contact->syncFromFayda([
                        'sub' => $contact->sub,
                        'name' => $name,
                        'phone_number' => $phone,
                        'email' => $email ?? $contact->email,
                        'identification_type' => $contact->identification_type ?: '2',
                        'identification_number' => $contact->identification_number ?: (string) $contact->sub,
                    ]);
                }
            }

            $this->linkContact(
                $contact->fresh(),
                $company,
                CompanyRole::Member,
                switchTo: false,
                isActive: $isActive,
            );

            Log::info('Owner created company member', [
                'company_id' => $company->id,
                'owner_id' => $actor->id,
                'member_contact_id' => $contact->id,
                'is_active' => $isActive,
                'linked_existing_contact' => $linkedExisting,
            ]);

            return [
                'contact' => $contact->fresh(['company', 'memberships.company']),
                'linked_existing' => $linkedExisting,
            ];
        });

        $contact = $result['contact'];
        $linkedExisting = $result['linked_existing'];

        try {
            $this->notifications->memberAddedToCompany($company->fresh() ?? $company, $contact, $actor);
        } catch (\Throwable $e) {
            report($e);
        }

        $row = $this->listCurrentCompanyMembers($actor)
            ->first(fn (array $m) => ($m['public_id'] ?? null) === $contact->public_id);

        return [
            'contact' => $contact,
            'linked_existing' => $linkedExisting,
            'member' => $row ?? [
                'public_id' => $contact->public_id,
                'name' => $contact->name,
                'phone_number' => $contact->phone_number,
                'email' => $contact->email,
                'role' => CompanyRole::Member->value,
                'is_active' => $isActive,
                'is_owner' => false,
                'awaiting_fayda' => $this->isPlaceholderContact($contact),
                'identity_verified' => $contact->isIdentityVerified(),
                'identity_verified_via' => $contact->identityVerifiedViaValue(),
                'fayda_verified' => (bool) $contact->fayda_verified,
                'permissions' => CompanyMemberPermission::defaultsForMember(),
            ],
        ];
    }

    /**
     * Membership sync after Fayda login: keep inactive memberships inactive and out of context.
     * Active memberships may become the portal company context.
     */
    public function trySyncMembershipsOnFaydaLogin(Contact $contact): void
    {
        $contact->loadMissing('memberships.company');

        $memberships = $contact->memberships;
        if ($memberships->isEmpty()) {
            return;
        }

        $current = $memberships->first(
            fn (CompanyMembership $m) => (int) $m->company_id === (int) $contact->current_company_id,
        );

        // Current context disabled → move off it (or clear).
        if ($current && ! $current->is_active) {
            $this->switchToFallbackCompany($contact, exceptCompanyId: (int) $current->company_id);

            return;
        }

        // Already on an active membership — leave it.
        if ($current && $current->is_active) {
            $company = $current->company;
            if ($company) {
                $contact->forceFill(['profile_completed_at' => $contact->profile_completed_at ?? now()]);
                $this->syncContactCompanyFields($contact->fresh(), $company);
            }

            return;
        }

        // No current company: prefer first active membership (owner first).
        $active = $memberships
            ->filter(fn (CompanyMembership $m) => $m->is_active)
            ->sortBy(fn (CompanyMembership $m) => $m->isOwner() ? 0 : 1)
            ->first();

        if (! $active) {
            // All memberships inactive — never auto-enable.
            if ($contact->current_company_id) {
                $contact->forceFill([
                    'current_company_id' => null,
                    'company_name' => null,
                    'company_tin' => null,
                    'company_phone' => null,
                    'company_email' => null,
                    'company_address' => null,
                    'profile_completed_at' => null,
                ])->save();
            }

            return;
        }

        $company = $active->company ?? Company::query()->find($active->company_id);
        if (! $company) {
            return;
        }

        $contact->forceFill([
            'current_company_id' => $company->id,
            'profile_completed_at' => now(),
        ]);
        $this->syncContactCompanyFields($contact, $company);
    }

    public function isPlaceholderContact(Contact $contact): bool
    {
        $sub = (string) $contact->sub;

        return str_starts_with($sub, 'invite-')
            || str_starts_with($sub, 'mvas-contact-');
    }

    /**
     * After Fayda login: claim exactly one ownerless approved company for this partner.
     * Prefer legacy_mvas_id match (migrated dump), else unique phone last-9 match.
     * Orphan / ambiguous companies stay ownerless for admin Assign owner.
     */
    /**
     * Portal OTP allowlist: phone already linked to a TIN-validated company, or
     * uniquely matches an ownerless TIN-validated company phone (first-time claim).
     */
    public function phoneIsEligibleForPortalOtp(string $phone): bool
    {
        $normalized = PhoneNumber::normalize($phone);
        if ($normalized === '' || ! PhoneNumber::isValidEthioTelecomMobile($normalized)) {
            return false;
        }

        $contact = Contact::query()->where('phone_number', $normalized)->first();
        if ($contact && $this->contactHasTinValidatedCompany($contact)) {
            return true;
        }

        return $this->findClaimableTinValidatedCompanyByPhone($normalized) !== null;
    }

    /**
     * Contact has at least one active membership on a company with ERCA-verified valid TIN.
     * Owners and members both qualify — members must not be forced through company create.
     */
    public function contactHasTinValidatedCompany(Contact $contact): bool
    {
        $contact->loadMissing(['memberships.company']);

        foreach ($contact->memberships as $membership) {
            if (! $membership->is_active) {
                continue;
            }

            $company = $membership->company;
            if ($company && $company->isTinValidated()) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when the partner has no active company membership (must create or join).
     * Members of existing companies return false even if they are not owners.
     */
    public function contactNeedsCompanyOnboarding(Contact $contact): bool
    {
        $contact->loadMissing('memberships');

        return ! $contact->memberships->contains(
            fn (CompanyMembership $m) => (bool) $m->is_active,
        );
    }

    /**
     * After OTP/Fayda: put members (and owners) onto an active company context when missing.
     */
    public function ensureActiveCompanyContext(Contact $contact): Contact
    {
        $this->trySyncMembershipsOnFaydaLogin($contact->fresh(['memberships.company']) ?? $contact);

        return $contact->fresh(['company', 'memberships.company']) ?? $contact;
    }

    /**
     * Exactly one active, TIN-validated, ownerless company whose phone matches.
     */
    public function findClaimableTinValidatedCompanyByPhone(string $phone): ?Company
    {
        $last9 = PhoneNumber::normalize($phone);
        if ($last9 === '' || ! PhoneNumber::isValidEthioTelecomMobile($last9)) {
            return null;
        }

        $candidates = Company::query()
            ->where('is_active', true)
            ->tinApproved()
            ->where(function ($q) use ($last9) {
                $q->whereRaw(
                    "RIGHT(REGEXP_REPLACE(COALESCE(claim_phone, phone, ''), '[^0-9]', '', 'g'), 9) = ?",
                    [$last9],
                );
            })
            ->whereDoesntHave('memberships', function ($query) {
                $query->where('role', CompanyRole::Owner->value);
            })
            ->limit(3)
            ->get()
            ->filter(fn (Company $company) => $company->isTinValidated())
            ->values();

        if ($candidates->count() !== 1) {
            return null;
        }

        $company = $candidates->first();

        return $company && ! $company->hasOwner() ? $company : null;
    }

    public function tryAutoClaimMigratedCompanyByPhone(Contact $contact): ?Company
    {
        // Already in an active company context, or already has an active membership.
        if (filled($contact->current_company_id)) {
            return null;
        }

        if ($contact->memberships()->where('is_active', true)->exists()) {
            return null;
        }

        $company = null;

        if (filled($contact->legacy_mvas_id)) {
            $company = Company::query()
                ->where('legacy_mvas_id', $contact->legacy_mvas_id)
                ->where('is_active', true)
                ->where('tin_validated', true)
                ->whereDoesntHave('memberships', function ($query) {
                    $query->where('role', CompanyRole::Owner->value);
                })
                ->first();
        }

        if (! $company) {
            $last9 = \App\Support\PhoneNumber::normalize((string) $contact->phone_number);
            if ($last9 === '' || ! \App\Support\PhoneNumber::isValidLocalMobile($last9)) {
                return null;
            }

            $candidates = Company::query()
                ->where('is_active', true)
                ->where('tin_validated', true)
                ->whereRaw(
                    "RIGHT(REGEXP_REPLACE(COALESCE(claim_phone, phone, ''), '[^0-9]', '', 'g'), 9) = ?",
                    [$last9],
                )
                ->whereDoesntHave('memberships', function ($query) {
                    $query->where('role', CompanyRole::Owner->value);
                })
                ->limit(3)
                ->get();

            if ($candidates->count() !== 1) {
                if ($candidates->count() > 1) {
                    Log::warning('Fayda auto-claim skipped — ambiguous company phone match', [
                        'contact_id' => $contact->id,
                        'phone_last9' => $last9,
                        'company_ids' => $candidates->pluck('id')->all(),
                    ]);
                }

                return null;
            }

            $company = $candidates->first();
        }

        return DB::transaction(function () use ($contact, $company) {
            if ($company->hasOwner()) {
                return null;
            }

            $this->linkContact($contact, $company, CompanyRole::Owner, switchTo: true);

            if (! filled($company->created_by_contact_id)) {
                $company->forceFill([
                    'created_by_contact_id' => $contact->id,
                ])->save();
            }

            Log::info('Fayda auto-claimed migrated company', [
                'contact_id' => $contact->id,
                'company_id' => $company->id,
                'legacy_mvas_id' => $company->legacy_mvas_id,
                'company_tin' => $company->tin,
            ]);

            return $company->fresh();
        });
    }

    /**
     * Admin verification: assign an owner to an orphan (ownerless) company.
     */
    public function adminAssignOwner(Company $company, Contact $contact, User $admin, ?string $note = null): Company
    {
        if ($company->hasOwner()) {
            throw ValidationException::withMessages([
                'owner' => 'This company already has an owner.',
            ]);
        }

        if (! $contact->is_active) {
            throw ValidationException::withMessages([
                'owner' => 'Cannot assign an inactive partner as owner.',
            ]);
        }

        return DB::transaction(function () use ($company, $contact, $admin, $note) {
            $this->linkContact($contact, $company, CompanyRole::Owner, switchTo: true);

            $company->forceFill([
                'created_by_contact_id' => $company->created_by_contact_id ?: $contact->id,
                'approval_status' => CompanyApprovalStatus::Approved,
                'is_active' => true,
                'approved_by_user_id' => $admin->id,
                'approved_at' => $company->approved_at ?? now(),
                'approval_note' => trim((string) ($note ?: $company->approval_note ?: 'Owner assigned by admin after verification.')),
            ])->save();

            Log::info('Admin assigned owner to orphan company', [
                'company_id' => $company->id,
                'contact_id' => $contact->id,
                'admin_id' => $admin->id,
            ]);

            return $company->fresh(['memberships.contact']);
        });
    }

    public function setMembershipActive(Company $company, Contact $member, bool $active, User|Contact|null $actor = null): Contact
    {
        $membership = $this->membershipFor($member, $company);
        if (! $membership) {
            throw ValidationException::withMessages([
                'member' => 'This partner is not a member of this company.',
            ]);
        }

        if ($membership->isOwner() && ! $active) {
            $otherActive = CompanyMembership::query()
                ->where('company_id', $company->id)
                ->where('contact_id', '!=', $member->id)
                ->where('is_active', true)
                ->exists();

            if ($otherActive) {
                throw ValidationException::withMessages([
                    'member' => 'Cannot disable the owner while other active members remain. Transfer ownership first.',
                ]);
            }
        }

        $membership->forceFill(['is_active' => $active])->save();

        if (! $active && (int) $member->current_company_id === (int) $company->id) {
            $this->switchToFallbackCompany($member, exceptCompanyId: $company->id);
        }

        return $member->fresh(['company', 'memberships.company']);
    }

    public function assertCanAccessCompany(Contact $contact): void
    {
        if (! $contact->current_company_id) {
            throw ValidationException::withMessages([
                'company' => 'Create a company with a unique TIN number (or join an approved company) before using VAS services.',
            ]);
        }

        if (! $contact->hasActiveCompanyMembership()) {
            throw ValidationException::withMessages([
                'company' => 'Your membership for this company is disabled. Contact your company owner or an administrator.',
            ]);
        }

        if (! filled($contact->company->tin)) {
            throw ValidationException::withMessages([
                'company' => 'A valid company TIN number is required before using VAS services.',
            ]);
        }

        if (! TinNumber::isValid($contact->company->tin)) {
            throw ValidationException::withMessages([
                'company_tin' => TinNumber::message().' Update your company TIN number before submitting service requests.',
            ]);
        }

        // TIN number must be found in ERCA.
        if (! $contact->company->isTinValidated()) {
            throw ValidationException::withMessages([
                'company_tin' => 'Confirm your company TIN number with ERCA before using VAS services.',
            ]);
        }

        // TIN number found but name mismatch — resolve keep-both / update before services.
        if ($contact->company->needsErcaNameConsent()) {
            throw ValidationException::withMessages([
                'company' => 'Confirm your company name with ERCA (keep both names, or update TIN number) before using VAS services.',
            ]);
        }

        // TIN number found but legal name missing — partner must enter a company name.
        if ($contact->company->needsErcaNameEntry()) {
            throw ValidationException::withMessages([
                'company' => 'Enter your company name to continue. ERCA confirmed the TIN number but did not return a legal name.',
            ]);
        }

        // TIN number found but legal name missing / unresolved — block until name is settled.
        if (! $contact->company->isErcaNameResolved()) {
            throw ValidationException::withMessages([
                'company' => 'Your TIN number is verified, but the company name must be confirmed before using VAS services.',
            ]);
        }

        // Block VAS ops for this company only — partner may still sign in, switch, or create another.
        if ($contact->company->isApproved() && ! $contact->company->is_active) {
            throw ValidationException::withMessages([
                'company' => 'This company is deactivated, so service requests are disabled. Switch to another company or create a new company with a valid TIN number.',
            ]);
        }
    }

    /**
     * @deprecated Sign-in is no longer blocked by deactivated companies.
     * Partners may log in and switch/create another company; VAS ops use {@see assertCanAccessCompany}.
     */
    public function assertPortalSignInAllowed(Contact $contact): void
    {
        // Kept for backward compatibility with any remaining callers — no-op.
    }

    /**
     * Whether the contact has at least one usable company context (active or still onboarding).
     * Not used to block portal sign-in.
     */
    public function contactMayUsePortal(Contact $contact): bool
    {
        $contact->loadMissing(['memberships.company']);

        $companies = $contact->memberships
            ->map(fn (CompanyMembership $m) => $m->company)
            ->filter();

        if ($companies->isEmpty()) {
            return true;
        }

        foreach ($companies as $company) {
            if ($company->is_active) {
                return true;
            }

            $status = $company->approval_status instanceof CompanyApprovalStatus
                ? $company->approval_status
                : CompanyApprovalStatus::tryFrom((string) $company->approval_status);

            // Still onboarding / needs fixes — usable portal context.
            if ($status !== CompanyApprovalStatus::Approved) {
                return true;
            }
        }

        // All linked companies are approved but switched off by admin.
        return false;
    }

    /**
     * Company deactivated: do not revoke portal sessions.
     * Partners keep access to switch company or register another TIN.
     * VAS requests for the deactivated company remain blocked via {@see assertCanAccessCompany}.
     */
    public function revokePortalAccessForInactiveCompany(Company $company): int
    {
        return 0;
    }

    /**
     * Partner submits / corrects Ethiopian TIN number (even after company approval).
     * Clears tin_validated so admin must re-confirm.
     */
    public function submitCompanyTin(Contact $contact, string $rawTin): Contact
    {
        if (! $contact->current_company_id || ! $contact->hasActiveCompanyMembership()) {
            throw ValidationException::withMessages([
                'company' => 'Link an active company before submitting a TIN number.',
            ]);
        }

        $isOwner = $this->roleOf($contact) === CompanyRole::Owner;
        if (! $isOwner && ! $this->contactHasPermission($contact, CompanyMemberPermission::EditCompanyProfile)) {
            throw ValidationException::withMessages([
                'company_tin' => 'Only the company owner (or a member with edit permission) can update the TIN number.',
            ]);
        }

        $company = $contact->company;
        if (! $company) {
            throw ValidationException::withMessages(['company' => 'Company not found.']);
        }

        $this->assertErcaIdentityEditable($company);

        $tin = $this->normalizeEthiopianTin($rawTin);
        $this->ercaSearchGuard->assertCanSearch($contact, $tin);
        $this->assertUniqueTin($tin, $company->id);

        // Never persist a TIN that ERCA does not recognize.
        $this->ercaTin->assertTinExistsInErca($tin);

        $wasValidated = (bool) $company->tin_validated;

        $company->fill([
            'tin' => $tin,
            'tin_validated' => false,
        ])->save();

        if ($wasValidated) {
            $this->recordStatusHistory($company, 'tin_cleared', null, $contact, 'Partner updated TIN number');
        }

        $this->syncAllMembersDenormalizedFields($company);

        $this->ercaSearchGuard->recordSearch($contact, $tin);

        // Apply name-match / consent state from the lookup (uses cache from assert).
        $this->ercaTin->verifyCompany($company->fresh() ?? $company, force: true);

        return $contact->fresh(['company', 'memberships.company']);
    }

    /**
     * Partner consents after ERCA legal name ≠ entered company name.
     *
     * @param  'use_legal'|'keep_both'  $action
     */
    public function applyErcaNameConsent(Contact $contact, string $action): Contact
    {
        if (! $contact->current_company_id || ! $contact->hasActiveCompanyMembership()) {
            throw ValidationException::withMessages([
                'company' => 'Link an active company before confirming the legal name.',
            ]);
        }

        $isOwner = $this->roleOf($contact) === CompanyRole::Owner;
        if (! $isOwner && ! $this->contactHasPermission($contact, CompanyMemberPermission::EditCompanyProfile)) {
            throw ValidationException::withMessages([
                'consent' => 'Only the company owner (or a member with edit permission) can confirm the legal name.',
            ]);
        }

        $company = $contact->company;
        if (! $company) {
            throw ValidationException::withMessages(['company' => 'Company not found.']);
        }

        $this->ercaTin->applyNameConsent($company, $contact, $action);
        $this->syncAllMembersDenormalizedFields($company->fresh() ?? $company);

        return $contact->fresh(['company', 'memberships.company']);
    }

    /**
     * Partner enters company name when ERCA found the TIN number but returned no legal name.
     */
    public function applyErcaPartnerEnteredName(Contact $contact, string $name): Contact
    {
        if (! $contact->current_company_id || ! $contact->hasActiveCompanyMembership()) {
            throw ValidationException::withMessages([
                'company' => 'Link an active company before entering the company name.',
            ]);
        }

        $isOwner = $this->roleOf($contact) === CompanyRole::Owner;
        if (! $isOwner && ! $this->contactHasPermission($contact, CompanyMemberPermission::EditCompanyProfile)) {
            throw ValidationException::withMessages([
                'company_name' => 'Only the company owner (or a member with edit permission) can enter the company name.',
            ]);
        }

        $company = $contact->company;
        if (! $company) {
            throw ValidationException::withMessages(['company' => 'Company not found.']);
        }

        $this->ercaTin->applyPartnerEnteredName($company, $contact, $name);
        $this->syncAllMembersDenormalizedFields($company->fresh() ?? $company);

        return $contact->fresh(['company', 'memberships.company']);
    }

    /**
     * Best-effort ERCA verify — never block company create/update on upstream outages.
     */
    protected function safeErcaVerify(Company $company, bool $force = false): void
    {
        try {
            $this->ercaTin->verifyCompany($company, force: $force);
        } catch (ValidationException $e) {
            // Rate-limit / validation during create: leave schedule fields; partner can retry via TIN number submit.
            Log::info('ERCA verify deferred', [
                'company_id' => $company->id,
                'errors' => $e->errors(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('ERCA verify failed', [
                'company_id' => $company->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * TIN number must be confirmed via ERCA — admin cannot attest alone.
     * Forces an ERCA check and unlocks only when ERCA resolves the identity.
     */
    public function markTinValidated(Company $company, ?User $admin = null): Company
    {
        if (! TinNumber::isValid($company->tin)) {
            throw ValidationException::withMessages([
                'tin' => TinNumber::message(),
            ]);
        }

        $this->ercaTin->verifyCompany($company->fresh() ?? $company, force: true);
        $fresh = $this->syncTinValidatedFromErca($company->fresh() ?? $company);

        if (! $fresh->isTinValidated()) {
            throw ValidationException::withMessages([
                'tin' => 'TIN number must be confirmed in ERCA before it is marked verified.',
            ]);
        }

        return $fresh->fresh(['approvedBy']) ?? $fresh;
    }

    /**
     * After ERCA finds the TIN number, mark tin_validated and set Active.
     * Name mismatch still needs partner consent before services unlock.
     */
    public function syncTinValidatedFromErca(Company $company): Company
    {
        $company->refresh();

        if (! $company->isTinValidated()) {
            if ($company->tin_validated) {
                $company->forceFill([
                    'tin_validated' => false,
                ])->save();
            }

            return $company->fresh() ?? $company;
        }

        if (! $company->tin_validated) {
            $company->forceFill([
                'tin_validated' => true,
            ])->save();

            $this->recordStatusHistory(
                $company,
                'tin_validated',
                null,
                null,
                'TIN number confirmed via ERCA',
                ['auto' => true, 'via' => 'erca'],
            );
        }

        // ERCA-verified TIN number ⇒ company Active must be on.
        return $this->ensureApprovedWhenTinValidated($company->fresh() ?? $company);
    }

    /**
     * Keep legacy approval_status / Active in sync when ERCA TIN number is confirmed.
     */
    public function ensureApprovedWhenTinValidated(Company $company, ?User $admin = null): Company
    {
        $company->refresh();

        if (! $company->isTinValidated()) {
            return $company;
        }

        $status = $company->approval_status instanceof CompanyApprovalStatus
            ? $company->approval_status
            : CompanyApprovalStatus::tryFrom((string) ($company->approval_status ?: ''));

        $alreadyApproved = $status === CompanyApprovalStatus::Approved;
        $alreadyActive = (bool) $company->is_active;

        if ($alreadyApproved && $alreadyActive) {
            return $company;
        }

        $admin ??= auth()->user() instanceof User ? auth()->user() : null;

        $beforeActive = $alreadyActive;

        $company->forceFill([
            'approval_status' => CompanyApprovalStatus::Approved,
            'approved_by_user_id' => $admin?->id ?? $company->approved_by_user_id,
            'approved_at' => $company->approved_at ?? now(),
            'approval_note' => filled($company->approval_note)
                ? $company->approval_note
                : 'Approved with TIN number validation.',
            'is_active' => true,
        ])->save();

        if (! $alreadyApproved) {
            $this->recordStatusHistory(
                $company,
                'approved',
                $admin,
                null,
                'Approved with TIN number validation.',
            );
        } elseif (! $beforeActive) {
            $this->recordStatusHistory(
                $company,
                'activated',
                $admin,
                null,
                'Activated after ERCA TIN number verification.',
            );
        }

        return $company->fresh(['approvedBy']) ?? $company;
    }

    /**
     * Clear a false / mistaken TIN number approval when the stored value is not a valid Ethiopian TIN number.
     *
     * @return array{cleared: bool, notified: bool, skipped: bool, reason?: string|null}
     */
    public function clearInvalidTinApproval(Company $company, bool $notify = true): array
    {
        $company->refresh();

        if (TinNumber::isValid($company->tin)) {
            return ['cleared' => false, 'skipped' => true, 'reason' => 'tin_format_valid'];
        }

        $wasValidated = (bool) $company->tin_validated;

        if ($wasValidated) {
            $company->forceFill([
                'tin_validated' => false,
            ])->save();

            $this->recordStatusHistory(
                $company,
                'tin_cleared',
                null,
                null,
                'Automated scan: TIN number format invalid while tin_validated was true',
            );
        }

        // Default: SMS only when clearing a false approval (avoids hourly spam on placeholders).
        // Callers pass $notify=true for unvalidated rows only with an explicit --notify-all run.
        if ($notify && $wasValidated) {
            $this->notifications->companyTinInvalid(
                $company->fresh(['activeMembers']) ?? $company,
                true,
            );
        }

        return [
            'cleared' => $wasValidated,
            'notified' => $notify && $wasValidated,
            'skipped' => ! $wasValidated,
            'reason' => $wasValidated ? null : 'already_unvalidated',
        ];
    }

    /**
     * Notify owner that the company TIN number format is invalid (one-shot / --notify-all scans).
     */
    public function notifyInvalidTin(Company $company, bool $hadFalseApproval = false): void
    {
        $this->notifications->companyTinInvalid(
            $company->fresh(['activeMembers']) ?? $company,
            $hadFalseApproval,
        );
    }

    /**
     * Log admin edits to Active / approval from the company form.
     * TIN number OK is ERCA-only and is not changed from the admin form.
     *
     * @param  array{approval_status?: mixed, is_active?: mixed, tin_validated?: mixed}  $before
     */
    public function logAdminConditionChanges(Company $company, array $before, ?User $admin = null, ?string $note = null): void
    {
        $admin ??= auth()->user() instanceof User ? auth()->user() : null;

        $beforeApproval = (string) ($before['approval_status'] ?? '');
        $afterApproval = $company->approval_status instanceof CompanyApprovalStatus
            ? $company->approval_status->value
            : (string) $company->approval_status;
        $beforeActive = (bool) ($before['is_active'] ?? false);
        $afterActive = (bool) $company->is_active;

        if ($beforeApproval === $afterApproval && $beforeActive === $afterActive) {
            return;
        }

        $action = 'conditions_updated';
        if ($beforeActive !== $afterActive && $beforeApproval === $afterApproval) {
            $action = $afterActive ? 'activated' : 'deactivated';
        } elseif ($beforeApproval !== $afterApproval && $beforeActive === $afterActive) {
            $action = $afterApproval === CompanyApprovalStatus::Approved->value
                ? 'approved'
                : ($afterApproval === CompanyApprovalStatus::Rejected->value ? 'rejected' : 'conditions_updated');
        }

        $this->recordStatusHistory(
            $company->fresh(),
            $action,
            $admin,
            null,
            $note,
            [
                'before' => [
                    'approval_status' => $beforeApproval !== '' ? $beforeApproval : null,
                    'is_active' => $beforeActive,
                ],
                'after' => [
                    'approval_status' => $afterApproval !== '' ? $afterApproval : null,
                    'is_active' => $afterActive,
                ],
            ],
        );
    }

    /**
     * @param  array<string, mixed>|null  $meta
     */
    public function recordStatusHistory(
        Company $company,
        string $action,
        ?User $actorUser = null,
        ?Contact $actorContact = null,
        ?string $note = null,
        ?array $meta = null,
    ): CompanyStatusHistory {
        $approval = $company->approval_status instanceof CompanyApprovalStatus
            ? $company->approval_status->value
            : (string) ($company->approval_status ?? '');

        return CompanyStatusHistory::query()->create([
            'company_id' => $company->id,
            'action' => $action,
            'approval_status' => $approval !== '' ? $approval : null,
            'is_active' => (bool) $company->is_active,
            'tin_validated' => (bool) $company->tin_validated,
            'actor_user_id' => $actorUser?->id,
            'actor_contact_id' => $actorContact?->id,
            'note' => filled($note) ? trim($note) : null,
            'meta' => $meta,
            'created_at' => now(),
        ]);
    }

    /**
     * Contact ids linked to a company (active or inactive memberships).
     *
     * @return \Illuminate\Support\Collection<int, int>
     */
    public function companyContactIds(int $companyId): \Illuminate\Support\Collection
    {
        return CompanyMembership::query()
            ->where('company_id', $companyId)
            ->pluck('contact_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
    }

    /**
     * Active company members may open any company service request (not only their own).
     * Contacts without an active company still only see tickets they submitted.
     */
    public function contactCanAccessCompanyTicket(Contact $viewer, Ticket $ticket): bool
    {
        if ((int) $ticket->contact_id === (int) $viewer->id) {
            return true;
        }

        if (! $viewer->current_company_id || ! $viewer->hasActiveCompanyMembership()) {
            return false;
        }

        $companyId = (int) $viewer->current_company_id;

        if ($this->companyContactIds($companyId)->contains((int) $ticket->contact_id)) {
            return true;
        }

        $ticket->loadMissing('subscription:id,company_id');
        if ($ticket->subscription && (int) $ticket->subscription->company_id === $companyId) {
            return true;
        }

        return false;
    }

    public function assertCanAccessCompanyTicket(Contact $viewer, Ticket $ticket): void
    {
        abort_unless($this->contactCanAccessCompanyTicket($viewer, $ticket), 404);
    }

    public function transferOwnership(Company $company, Contact $newOwner, User $actor): Company
    {
        return DB::transaction(function () use ($company, $newOwner) {
            $newMembership = $this->membershipFor($newOwner, $company);
            if (! $newMembership) {
                throw ValidationException::withMessages([
                    'owner' => 'The new owner must already be a member of this company.',
                ]);
            }

            $currentOwnerMembership = CompanyMembership::query()
                ->where('company_id', $company->id)
                ->where('role', CompanyRole::Owner->value)
                ->first();

            if ($currentOwnerMembership && (int) $currentOwnerMembership->contact_id === (int) $newOwner->id) {
                return $company->fresh(['memberships']);
            }

            if ($currentOwnerMembership) {
                $currentOwnerMembership->forceFill(['role' => CompanyRole::Member->value])->save();
            }

            $newMembership->forceFill([
                'role' => CompanyRole::Owner->value,
                'is_active' => true,
            ])->save();

            return $company->fresh(['memberships']);
        });
    }

    public function unlinkContact(Contact $contact, ?Company $company = null): void
    {
        $company ??= $contact->company;
        if (! $company) {
            return;
        }

        $contact->forceFill(['current_company_id' => $company->id])->save();
        $this->assertOwnerMayLeave($contact->fresh());

        CompanyMembership::query()
            ->where('contact_id', $contact->id)
            ->where('company_id', $company->id)
            ->delete();

        $this->switchToFallbackCompany($contact->fresh(), exceptCompanyId: $company->id);
    }

    public function switchCompany(Contact $contact, Company $company): Contact
    {
        $membership = $this->membershipFor($contact, $company);
        if (! $membership) {
            throw ValidationException::withMessages([
                'company' => 'You are not a member of that company.',
            ]);
        }
        if (! $membership->is_active) {
            throw ValidationException::withMessages([
                'company' => 'Your membership for that company is disabled.',
            ]);
        }

        $contact->forceFill(['current_company_id' => $company->id]);
        $this->syncContactCompanyFields($contact, $company);

        return $contact->fresh(['company', 'memberships.company']);
    }

    public function syncContactCompanyFields(Contact $contact, Company $company): void
    {
        $contact->forceFill([
            'company_name' => $company->name,
            'company_tin' => $company->tin,
            'company_phone' => $company->claimPhone(),
            'company_email' => $company->email,
            'company_address' => $company->address,
        ])->save();
    }

    public function syncAllMembersDenormalizedFields(Company $company): void
    {
        CompanyMembership::query()
            ->where('company_id', $company->id)
            ->with('contact')
            ->orderBy('id')
            ->each(function (CompanyMembership $membership) use ($company): void {
                $member = $membership->contact;
                if (! $member) {
                    return;
                }
                if ((int) $member->current_company_id === (int) $company->id) {
                    $this->syncContactCompanyFields($member, $company);
                }
            });
    }

    public function serializeContact(Contact $contact): array
    {
        $contact->loadMissing(['company', 'memberships.company']);
        $pending = $this->pendingRequestFor($contact);
        if ($pending) {
            $pending->loadMissing(['company', 'targetContact']);
        }
        $company = $contact->company;
        $approvalStatus = $company?->approval_status instanceof CompanyApprovalStatus
            ? $company->approval_status
            : ($company ? CompanyApprovalStatus::tryFrom((string) $company->approval_status) : null);
        $companyApproved = $company?->isApproved() === true;
        $membershipActive = $contact->current_company_id
            ? $contact->hasActiveCompanyMembership()
            : null;
        $memberCount = $contact->current_company_id
            ? CompanyMembership::query()->where('company_id', $contact->current_company_id)->count()
            : 0;
        $isOwner = $this->roleOf($contact) === CompanyRole::Owner;
        $currentMembership = $contact->membershipForCurrentCompany();
        $permissions = $currentMembership && $membershipActive
            ? $this->effectivePermissionsForMembership($currentMembership)
            : [];
        $canEditCompany = $membershipActive
            && $company
            && ! $company->isTinValidated()
            && ! $company->isErcaIdentityLocked()
            && in_array(CompanyMemberPermission::EditCompanyProfile->value, $permissions, true);
        $canDetach = (bool) $contact->current_company_id
            && $membershipActive
            && $companyApproved
            && ! $isOwner;
        $canManageMembers = $isOwner && $membershipActive && $companyApproved;
        $pendingMembershipCount = (
            $membershipActive
            && $companyApproved
            && $contact->current_company_id
            && in_array(CompanyMemberPermission::ManageMembershipRequests->value, $permissions, true)
        )
            ? CompanyChangeRequest::query()
                ->where('company_id', $contact->current_company_id)
                ->where('type', CompanyChangeType::Attach)
                ->where('status', CompanyChangeStatus::Pending)
                ->count()
            : 0;

        $data = $contact->toArray();
        $data['company_id'] = $contact->current_company_id;
        $data['current_company_id'] = $contact->current_company_id;
        $data['company'] = ($company && $membershipActive !== false && $contact->current_company_id) ? [
            'public_id' => $company->public_id,
            'name' => $company->name,
            'legal_name' => $company->legal_name,
            'tin' => $company->tin,
            'phone' => $company->claimPhone(),
            'claim_phone' => $company->claimPhone(),
            'erca_phone' => $company->ercaPhone(),
            'revenue_phone' => $company->revenuePhone(),
            'email' => $company->email,
            'address' => $company->address,
            'member_count' => $memberCount,
            'approval_status' => $approvalStatus?->value,
            'approval_note' => $company->approval_note,
            'is_approved' => $companyApproved,
            'is_active' => (bool) $company->is_active,
            'tin_validated' => $company->isTinValidated(),
            'tin_format_valid' => TinNumber::isValid($company->tin),
            'erca_tin_verified' => (bool) $company->erca_tin_verified,
            'erca_name_status' => $company->erca_name_status instanceof \App\Enums\ErcaNameStatus
                ? $company->erca_name_status->value
                : (string) ($company->erca_name_status ?: 'unchecked'),
            'erca_verified_at' => optional($company->erca_verified_at)?->toIso8601String(),
            'erca_last_checked_at' => optional($company->erca_last_checked_at)?->toIso8601String(),
            'needs_erca_name_consent' => $company->needsErcaNameConsent(),
            'needs_erca_name_entry' => $company->needsErcaNameEntry(),
            'erca_identity_locked' => $company->isErcaIdentityLocked(),
        ] : null;
        $data['company_role'] = $contact->company_role;
        $data['company_membership_active'] = $membershipActive;
        $data['company_can_detach'] = $canDetach;
        $data['company_can_edit'] = $canEditCompany;
        $data['company_can_manage_members'] = $canManageMembers;
        $data['company_permissions'] = $permissions;
        $data['company_permission_catalog'] = CompanyMemberPermission::catalog();
        // Owner must transfer ownership (admin-approved) before they can leave.
        $data['company_needs_ownership_transfer'] = $isOwner && $membershipActive && (bool) $contact->current_company_id;
        $data['pending_membership_requests_count'] = $pendingMembershipCount;
        $data['profile_completed'] = $contact->profile_completed;
        $data['fayda_verified'] = (bool) $contact->fayda_verified;
        $data['identity_verified'] = $contact->isIdentityVerified();
        $data['identity_verified_via'] = $contact->identityVerifiedViaValue();
        $data['identity_verified_at'] = optional($contact->identity_verified_at)?->toIso8601String();
        $data['needs_identity_consent'] = false;
        $data['needs_manual_name'] = false;
        $data['identity_proposal'] = null;
        if (! $contact->isIdentityVerified()) {
            // Do not reuse $pending — that holds the company change request below.
            $identityProposal = app(\App\Services\ContactIdentityService::class)->pendingProposal($contact);
            $data['needs_identity_consent'] = $identityProposal !== null;
            $data['identity_proposal'] = $identityProposal;
            $name = trim((string) $contact->name);
            $data['needs_manual_name'] = $identityProposal === null
                && ($name === '' || strcasecmp($name, 'Partner') === 0);
        }
        $data['memberships'] = $contact->memberships
            ->map(function (CompanyMembership $m) use ($contact) {
                $c = $m->company;
                $role = $m->role instanceof CompanyRole ? $m->role->value : (string) $m->role;

                return [
                    'company_public_id' => $c?->public_id,
                    'company_name' => $c?->name,
                    'company_tin' => $c?->tin,
                    'role' => $role,
                    'is_active' => (bool) $m->is_active,
                    'is_current' => (int) $m->company_id === (int) $contact->current_company_id,
                    'is_approved' => $c?->isApproved() === true,
                    'company_is_active' => $c ? (bool) $c->is_active : false,
                    'approval_status' => $c?->approval_status instanceof CompanyApprovalStatus
                        ? $c->approval_status->value
                        : ($c ? (string) $c->approval_status : null),
                    'tin_validated' => $c ? $c->isTinValidated() : false,
                ];
            })
            ->values()
            ->all();
        if ($membershipActive === false) {
            $data['company_name'] = null;
            $data['company_tin'] = null;
            $data['company_phone'] = null;
            $data['company_email'] = null;
            $data['company_address'] = null;
            $data['profile_completed'] = false;
        }
        $data['pending_company_request'] = $pending ? [
            'public_id' => $pending->public_id,
            'type' => $pending->type->value,
            'status' => $pending->status->value,
            'contact_note' => $pending->contact_note,
            'company' => $pending->company ? [
                'public_id' => $pending->company->public_id,
                'name' => $pending->company->name,
                'tin' => $pending->company->tin,
            ] : null,
            'target_contact' => $pending->targetContact ? [
                'public_id' => $pending->targetContact->public_id,
                'name' => $pending->targetContact->name,
            ] : null,
            'created_at' => optional($pending->created_at)?->toIso8601String(),
            'has_proposal' => $pending->hasProposal(),
            'has_letter' => $pending->hasLetter(),
        ] : null;

        return $data;
    }

    protected function roleOf(Contact $contact): ?CompanyRole
    {
        $membership = $contact->membershipForCurrentCompany();
        if (! $membership) {
            return null;
        }

        return $membership->role instanceof CompanyRole
            ? $membership->role
            : CompanyRole::tryFrom((string) $membership->role);
    }

    protected function membershipFor(Contact $contact, Company $company): ?CompanyMembership
    {
        return CompanyMembership::query()
            ->where('contact_id', $contact->id)
            ->where('company_id', $company->id)
            ->first();
    }

    protected function switchToFallbackCompany(Contact $contact, ?int $exceptCompanyId = null): void
    {
        $next = CompanyMembership::query()
            ->where('contact_id', $contact->id)
            ->where('is_active', true)
            ->when($exceptCompanyId, fn ($q) => $q->where('company_id', '!=', $exceptCompanyId))
            ->orderByRaw("CASE WHEN role = 'owner' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->first();

        if ($next) {
            $company = Company::query()->find($next->company_id);
            $contact->forceFill(['current_company_id' => $next->company_id]);
            if ($company) {
                $this->syncContactCompanyFields($contact, $company);
            } else {
                $contact->save();
            }

            return;
        }

        $contact->forceFill([
            'current_company_id' => null,
            'company_name' => null,
            'company_tin' => null,
            'company_phone' => null,
            'company_email' => null,
            'company_address' => null,
            'profile_completed_at' => null,
        ])->save();
    }

    /**
     * Company owner cannot leave while they are still the owner.
     * They must request an ownership transfer (letter + admin approval) first,
     * then leave as a member.
     */
    protected function assertOwnerMayLeave(Contact $contact): void
    {
        if ($this->roleOf($contact) !== CompanyRole::Owner || ! $contact->current_company_id) {
            return;
        }

        throw ValidationException::withMessages([
            'company' => 'Company owner cannot leave. Transfer ownership to another active member first (letter required; admin must approve). After you are no longer the owner, you can leave as a member.',
        ]);
    }

    protected function normalizeEthiopianTin(string $value): string
    {
        $tin = TinNumber::normalize($value);
        if (! TinNumber::isValid($tin)) {
            throw ValidationException::withMessages([
                'company_tin' => TinNumber::message(),
            ]);
        }

        return $tin;
    }

    protected function normalizeCode(string $value): string
    {
        return strtoupper(preg_replace('/\s+/', '', trim($value)) ?? '');
    }

    /** @deprecated Use normalizeCode */
    protected function normalizeTin(string $tin): string
    {
        return $this->normalizeCode($tin);
    }

    protected function assertUniqueTin(string $tin, ?int $ignoreCompanyId = null): void
    {
        // Include soft-deleted rows — a removed company must still block TIN reuse.
        $tinQuery = Company::withTrashed()->where('tin', $tin);
        if ($ignoreCompanyId) {
            $tinQuery->where('id', '!=', $ignoreCompanyId);
        }

        if ($tinQuery->exists()) {
            throw ValidationException::withMessages([
                'company_tin' => 'This TIN is already registered to another company. TIN numbers are unique — use “Join existing company”, or contact an administrator.',
                'tin' => 'This TIN is already registered to another company. TIN numbers are unique.',
            ]);
        }
    }

    /**
     * Phone/email for portal company create: copy from an existing company when the
     * partner already has one; otherwise use the signed-in contact identity.
     *
     * @return array{0: string, 1: ?string} [phone, email]
     */
    protected function resolveSharedCompanyContacts(Contact $contact): array
    {
        $source = $contact->company;
        if (! $source) {
            foreach ($contact->memberships as $membership) {
                if ($membership->company) {
                    $source = $membership->company;
                    break;
                }
            }
        }

        $identityPhone = \App\Support\PhoneNumber::normalize((string) $contact->phone_number);
        $identityEmail = \App\Support\EmailAddress::normalize($contact->email);

        if ($source) {
            $phone = \App\Support\PhoneNumber::normalize((string) ($source->claimPhone() ?: $identityPhone));
            $email = \App\Support\EmailAddress::normalize($source->email) ?? $identityEmail;

            return [$phone, $email];
        }

        return [$identityPhone, $identityEmail];
    }

    /** @return array{disk: string, path: string, original_name: string, size: int} */
    protected function storePdf(Contact $contact, UploadedFile $file, string $kind): array
    {
        $maxKb = $this->maxDocKb();
        if ($file->getSize() === false || $file->getSize() < 1) {
            throw ValidationException::withMessages([$kind => 'The file is empty.']);
        }
        if ($file->getSize() > $maxKb * 1024) {
            throw ValidationException::withMessages([$kind => "PDF must be {$maxKb} KB or smaller."]);
        }

        $ext = strtolower($file->getClientOriginalExtension() ?: '');
        $mime = strtolower((string) ($file->getMimeType() ?: ''));
        if ($ext !== 'pdf' || ! in_array($mime, ['application/pdf', 'application/x-pdf'], true)) {
            throw ValidationException::withMessages([$kind => 'Only PDF files are allowed.']);
        }

        $head = file_get_contents($file->getRealPath(), false, null, 0, 5);
        if ($head !== '%PDF-') {
            throw ValidationException::withMessages([$kind => 'The file does not look like a valid PDF.']);
        }

        $disk = 'local';
        $path = $file->storeAs(
            'company-changes/'.$contact->id,
            $kind.'-'.Str::uuid()->toString().'.pdf',
            $disk,
        );

        return [
            'disk' => $disk,
            'path' => $path,
            'original_name' => $file->getClientOriginalName() ?: "{$kind}.pdf",
            'size' => (int) $file->getSize(),
        ];
    }

    public function downloadPath(CompanyChangeRequest $request, string $kind): ?array
    {
        if ($kind === 'proposal' && $request->hasProposal()) {
            return [
                'disk' => $request->proposal_disk ?: 'local',
                'path' => $request->proposal_path,
                'name' => $request->proposal_original_name ?: 'proposal.pdf',
            ];
        }
        if ($kind === 'letter' && $request->hasLetter()) {
            return [
                'disk' => $request->letter_disk ?: 'local',
                'path' => $request->letter_path,
                'name' => $request->letter_original_name ?: 'letter.pdf',
            ];
        }

        return null;
    }
}
