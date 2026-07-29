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
use App\Models\Contact;
use App\Models\Ticket;
use App\Models\User;
use App\Support\TinNumber;
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

        return DB::transaction(function () use (
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

            return $fresh;
        });
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
                'company' => 'This company is already approved. Ask an administrator to update or change company details.',
            ]);
        }

        $phone = \App\Support\PhoneNumber::normalize((string) ($company->phone ?: $contact->phone_number));
        $email = trim((string) ($company->email ?: $contact->email));
        if ($phone === '' || ! \App\Support\PhoneNumber::isValidLocalMobile($phone)) {
            throw ValidationException::withMessages([
                'company' => 'A valid company phone is required. Use your existing company phone or sign in again.',
            ]);
        }

        $tin = $this->normalizeEthiopianTin($data['company_tin']);
        $this->assertUniqueTin($tin, $company->id);

        return DB::transaction(function () use ($contact, $company, $data, $tin, $phone, $email) {
            $company->fill([
                'name' => trim($data['company_name']),
                'tin' => $tin,
                'tin_validated' => false,
                'phone' => $phone,
                'email' => $email !== '' ? \App\Support\EmailAddress::normalize($email) : null,
                'address' => trim($data['company_address']),
                'approval_status' => CompanyApprovalStatus::Pending,
                'approval_note' => null,
                'approved_by_user_id' => null,
                'approved_at' => null,
                'is_active' => false,
            ])->save();

            $this->syncAllMembersDenormalizedFields($company);

            $fresh = $contact->fresh(['company', 'memberships.company']);
            $this->notifications->companyProfileSubmitted($fresh, $company);

            return $fresh;
        });
    }

    /**
     * Admin verifies required company info and activates the company (creator remains owner).
     */
    public function approveCompany(Company $company, User $admin, ?string $note = null): Company
    {
        if ($company->isApproved()) {
            return $company->fresh(['memberships', 'approvedBy']);
        }

        $required = [
            'name' => $company->name,
            'tin' => $company->tin,
            'phone' => $company->phone,
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
                'tin' => TinNumber::message().' Ask the partner to enter a valid TIN before approval.',
            ]);
        }

        if (! $company->hasOwner()) {
            throw ValidationException::withMessages([
                'owner' => 'Company must have an owner (the partner who created the profile) before approval.',
            ]);
        }

        $company->fill([
            'approval_status' => CompanyApprovalStatus::Approved,
            'approved_by_user_id' => $admin->id,
            'approved_at' => now(),
            'approval_note' => filled($note) ? trim($note) : null,
            'is_active' => true,
        ])->save();

        $fresh = $company->fresh(['memberships', 'approvedBy']);
        $owner = $fresh->ownerContact();
        if ($owner) {
            $this->notifications->companyProfileDecided($fresh, $owner, approved: true);
        }

        return $fresh;
    }

    public function rejectCompany(Company $company, User $admin, ?string $note = null): Company
    {
        if ($company->isApproved()) {
            throw ValidationException::withMessages([
                'status' => 'An approved company cannot be rejected. Edit details in admin instead.',
            ]);
        }

        $company->fill([
            'approval_status' => CompanyApprovalStatus::Rejected,
            'approved_by_user_id' => $admin->id,
            'approved_at' => now(),
            'approval_note' => filled($note) ? trim($note) : 'Incomplete company information.',
            'is_active' => false,
        ])->save();

        $fresh = $company->fresh(['memberships', 'approvedBy']);
        $owner = $fresh->ownerContact();
        if ($owner) {
            $this->notifications->companyProfileDecided($fresh, $owner, approved: false);
        }

        return $fresh;
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
            ->where('approval_status', CompanyApprovalStatus::Approved)
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
                'company_tin' => 'No active approved company found for this TIN. Create a new company instead.',
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
                'company' => 'Ownership can only be transferred after the company is approved.',
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
                'company' => 'Membership requests are available after admin approves this company profile.',
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
                'company' => 'Member management is available after admin approves your company profile.',
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
                'company' => 'Membership requests are available after admin approves your company profile.',
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
        $isActive = array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true;

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

            if ($contact?->is_banned) {
                throw ValidationException::withMessages([
                    'phone_number' => 'This partner is banned and cannot be added.',
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
                    'is_banned' => false,
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
                ->where('approval_status', CompanyApprovalStatus::Approved->value)
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
                ->where('approval_status', CompanyApprovalStatus::Approved->value)
                ->whereNotNull('phone')
                ->where('phone', '!=', '')
                ->whereRaw(
                    "RIGHT(REGEXP_REPLACE(COALESCE(phone, ''), '[^0-9]', '', 'g'), 9) = ?",
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

        if (! $contact->is_active || $contact->is_banned) {
            throw ValidationException::withMessages([
                'owner' => 'Cannot assign an inactive or banned partner as owner.',
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
        $this->assertPortalSignInAllowed($contact);

        if (! $contact->current_company_id) {
            throw ValidationException::withMessages([
                'company' => 'Create a company with a unique TIN (or join an approved company) before using VAS services.',
            ]);
        }

        if (! $contact->hasActiveCompanyMembership()) {
            throw ValidationException::withMessages([
                'company' => 'Your membership for this company is disabled. Contact your company owner or an administrator.',
            ]);
        }

        $contact->loadMissing('company');
        if (! $contact->company?->isApproved()) {
            throw ValidationException::withMessages([
                'company' => 'Services are locked until an administrator approves your company profile for this TIN. Complete company details and wait for approval.',
            ]);
        }

        if (! filled($contact->company->tin)) {
            throw ValidationException::withMessages([
                'company' => 'A valid company TIN is required before using VAS services.',
            ]);
        }

        if (! TinNumber::isValid($contact->company->tin)) {
            throw ValidationException::withMessages([
                'company_tin' => TinNumber::message().' Update your company TIN before submitting service requests.',
            ]);
        }

        if (! $contact->company->tin_validated) {
            throw ValidationException::withMessages([
                'company_tin' => 'This company\'s TIN is awaiting Ethio telecom validation. Switch to another company with a validated TIN, or wait until an administrator validates this one.',
            ]);
        }
    }

    /**
     * Portal sign-in / session: blocked when every linked company is admin-deactivated
     * (approved + is_active=false). Pending/rejected companies still allow sign-in.
     */
    public function assertPortalSignInAllowed(Contact $contact): void
    {
        if ($this->contactMayUsePortal($contact)) {
            return;
        }

        throw ValidationException::withMessages([
            'company' => 'Your company has been deactivated. Portal sign-in is disabled. Contact Ethio telecom.',
        ]);
    }

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

            // Still onboarding / needs fixes — allow portal access.
            if ($status !== CompanyApprovalStatus::Approved) {
                return true;
            }
        }

        // All linked companies are approved but switched off by admin.
        return false;
    }

    /**
     * When a company is turned off, revoke portal tokens for contacts who no longer
     * have any active/onboarding company.
     */
    public function revokePortalAccessForInactiveCompany(Company $company): int
    {
        if ($company->is_active) {
            return 0;
        }

        $revoked = 0;
        foreach ($this->companyContactIds((int) $company->id) as $contactId) {
            $contact = Contact::query()->find($contactId);
            if (! $contact || $this->contactMayUsePortal($contact)) {
                continue;
            }

            $contact->tokens()->delete();
            $revoked++;
        }

        return $revoked;
    }

    /**
     * Partner submits / corrects Ethiopian TIN (even after company approval).
     * Clears tin_validated so admin must re-confirm.
     */
    public function submitCompanyTin(Contact $contact, string $rawTin): Contact
    {
        if (! $contact->current_company_id || ! $contact->hasActiveCompanyMembership()) {
            throw ValidationException::withMessages([
                'company' => 'Link an active company before submitting a TIN.',
            ]);
        }

        $isOwner = $this->roleOf($contact) === CompanyRole::Owner;
        if (! $isOwner && ! $this->contactHasPermission($contact, CompanyMemberPermission::EditCompanyProfile)) {
            throw ValidationException::withMessages([
                'company_tin' => 'Only the company owner (or a member with edit permission) can update the TIN.',
            ]);
        }

        $company = $contact->company;
        if (! $company) {
            throw ValidationException::withMessages(['company' => 'Company not found.']);
        }

        $tin = $this->normalizeEthiopianTin($rawTin);
        $this->assertUniqueTin($tin, $company->id);

        $company->fill([
            'tin' => $tin,
            'tin_validated' => false,
        ])->save();

        $this->syncAllMembersDenormalizedFields($company);

        return $contact->fresh(['company', 'memberships.company']);
    }

    /**
     * Admin attests that the company TIN has been verified.
     */
    public function markTinValidated(Company $company): Company
    {
        if (! TinNumber::isValid($company->tin)) {
            throw ValidationException::withMessages([
                'tin' => TinNumber::message(),
            ]);
        }

        if ($company->tin_validated) {
            return $company->fresh();
        }

        $company->forceFill(['tin_validated' => true])->save();

        return $company->fresh();
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
            'company_phone' => $company->phone,
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
            && ! $companyApproved
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
            'tin' => $company->tin,
            'phone' => $company->phone,
            'email' => $company->email,
            'address' => $company->address,
            'member_count' => $memberCount,
            'approval_status' => $approvalStatus?->value,
            'approval_note' => $company->approval_note,
            'is_approved' => $companyApproved,
            'tin_validated' => (bool) $company->tin_validated,
            'tin_format_valid' => TinNumber::isValid($company->tin),
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
                    'approval_status' => $c?->approval_status instanceof CompanyApprovalStatus
                        ? $c->approval_status->value
                        : ($c ? (string) $c->approval_status : null),
                    'tin_validated' => (bool) ($c?->tin_validated),
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
        $tinQuery = Company::query()->where('tin', $tin);
        if ($ignoreCompanyId) {
            $tinQuery->where('id', '!=', $ignoreCompanyId);
        }

        if ($tinQuery->exists()) {
            throw ValidationException::withMessages([
                'company_tin' => 'This TIN is already registered to another company. TINs are unique — use “Join existing company”, or contact an administrator.',
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
            $phone = \App\Support\PhoneNumber::normalize((string) ($source->phone ?: $identityPhone));
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
