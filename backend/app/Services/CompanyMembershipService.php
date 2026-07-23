<?php

namespace App\Services;

use App\Enums\CompanyApprovalStatus;
use App\Enums\CompanyChangeStatus;
use App\Enums\CompanyChangeType;
use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\CompanyChangeRequest;
use App\Models\CompanyMembership;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
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
     *
     * @param  array{company_name: string, company_tin: string, company_license_number: string, company_phone: string, company_email: string, company_address: string}  $data
     */
    public function createCompanyForCustomer(Customer $customer, array $data): Customer
    {
        if ($this->pendingRequestFor($customer)) {
            throw ValidationException::withMessages([
                'company' => 'You already have a pending company request. Wait for a decision.',
            ]);
        }

        $tin = $this->normalizeCode($data['company_tin']);
        $license = $this->normalizeCode($data['company_license_number']);
        $this->assertUniqueIdentity($tin, $license);

        return DB::transaction(function () use ($customer, $data, $tin, $license) {
            $company = Company::query()->create([
                'name' => trim($data['company_name']),
                'tin' => $tin,
                'license_number' => $license,
                'phone' => trim($data['company_phone']),
                'email' => trim($data['company_email']),
                'address' => trim($data['company_address']),
                'is_active' => false,
                'approval_status' => CompanyApprovalStatus::Pending,
                'created_by_customer_id' => $customer->id,
            ]);

            $this->linkCustomer($customer, $company, CompanyRole::Owner, switchTo: true);
            $fresh = $customer->fresh(['company', 'memberships.company']);
            $this->notifications->companyProfileSubmitted($fresh);

            return $fresh;
        });
    }

    /**
     * Owner may edit company details only while awaiting (or after) admin rejection.
     * Once approved, only admin can update or remove company data in Filament.
     *
     * @param  array{company_name: string, company_tin: string, company_license_number: string, company_phone: string, company_email: string, company_address: string}  $data
     */
    public function updateOwnCompany(Customer $customer, array $data): Customer
    {
        if (! $customer->current_company_id) {
            return $this->createCompanyForCustomer($customer, $data);
        }

        $company = $customer->company;
        if (! $company) {
            throw ValidationException::withMessages(['company' => 'Company not found.']);
        }

        if ($this->roleOf($customer) !== CompanyRole::Owner) {
            throw ValidationException::withMessages([
                'company' => 'Only the company owner can update organisation details.',
            ]);
        }

        if (! $customer->hasActiveCompanyMembership()) {
            throw ValidationException::withMessages([
                'company' => 'Your membership for this company is disabled. Contact an administrator.',
            ]);
        }

        if ($company->isApproved()) {
            throw ValidationException::withMessages([
                'company' => 'This company is already approved. Ask an administrator to update or change company details.',
            ]);
        }

        $tin = $this->normalizeCode($data['company_tin']);
        $license = $this->normalizeCode($data['company_license_number']);
        $this->assertUniqueIdentity($tin, $license, $company->id);

        return DB::transaction(function () use ($customer, $company, $data, $tin, $license) {
            $company->fill([
                'name' => trim($data['company_name']),
                'tin' => $tin,
                'license_number' => $license,
                'phone' => trim($data['company_phone']),
                'email' => trim($data['company_email']),
                'address' => trim($data['company_address']),
                'approval_status' => CompanyApprovalStatus::Pending,
                'approval_note' => null,
                'approved_by_user_id' => null,
                'approved_at' => null,
                'is_active' => false,
            ])->save();

            $this->syncAllMembersDenormalizedFields($company);

            $fresh = $customer->fresh(['company', 'memberships.company']);
            $this->notifications->companyProfileSubmitted($fresh);

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
            'license_number' => $company->license_number,
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
        $owner = $fresh->ownerCustomer();
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
        $owner = $fresh->ownerCustomer();
        if ($owner) {
            $this->notifications->companyProfileDecided($fresh, $owner, approved: false);
        }

        return $fresh;
    }

    public function lookupByIdentity(string $tin, string $licenseNumber): ?Company
    {
        $tin = $this->normalizeCode($tin);
        $license = $this->normalizeCode($licenseNumber);
        if ($tin === '' || $license === '') {
            return null;
        }

        return Company::query()
            ->where('tin', $tin)
            ->where('license_number', $license)
            ->where('is_active', true)
            ->where('approval_status', CompanyApprovalStatus::Approved)
            ->first();
    }

    /** @deprecated Use lookupByIdentity */
    public function lookupByTin(string $tin): ?Company
    {
        $tin = $this->normalizeCode($tin);
        if ($tin === '') {
            return null;
        }

        return Company::query()->where('tin', $tin)->where('is_active', true)->first();
    }

    public function requestAttach(
        Customer $customer,
        string $tin,
        string $licenseNumber,
        ?string $note = null,
    ): CompanyChangeRequest {
        if ($this->pendingRequestFor($customer)) {
            throw ValidationException::withMessages([
                'company_tin' => 'You already have a pending company request.',
            ]);
        }

        $company = $this->lookupByIdentity($tin, $licenseNumber);
        if (! $company) {
            throw ValidationException::withMessages([
                'company_tin' => 'No active company found for this TIN and license number. Create a new company instead.',
            ]);
        }

        if ($this->membershipFor($customer, $company)) {
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
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'type' => CompanyChangeType::Attach,
            'status' => CompanyChangeStatus::Pending,
            'customer_note' => filled($note) ? trim($note) : null,
        ]);

        $this->notifications->companyChangeRequested($request);

        return $request->load(['company', 'customer']);
    }

    /**
     * Personal leave: member/owner detaches themselves from the current company immediately.
     * No admin approval. Membership (join) still requires company-owner approval.
     */
    public function leaveCompany(Customer $customer, ?string $note = null): Customer
    {
        if (! $customer->current_company_id) {
            throw ValidationException::withMessages([
                'company' => 'Select a company context before leaving.',
            ]);
        }

        if (! $customer->hasActiveCompanyMembership()) {
            throw ValidationException::withMessages([
                'company' => 'Your membership for this company is disabled. Contact an administrator.',
            ]);
        }

        $this->assertOwnerMayLeave($customer);

        $company = $customer->company;
        if (! $company) {
            throw ValidationException::withMessages(['company' => 'Company not found.']);
        }

        $owner = $company->ownerCustomer();
        $this->unlinkCustomer($customer, $company);

        if ($owner && (int) $owner->id !== (int) $customer->id) {
            $this->notifications->memberLeftCompany($company, $owner, $customer, $note);
        }

        return $customer->fresh(['company', 'memberships.company']);
    }

    /** @deprecated Use leaveCompany — detach is personal and immediate. */
    public function requestDetach(
        Customer $customer,
        ?string $note,
        UploadedFile $proposal,
        UploadedFile $letter,
    ): CompanyChangeRequest {
        unset($proposal, $letter);
        $this->leaveCompany($customer, $note);

        // Keep return type for any legacy callers; mark as auto-approved audit row.
        return CompanyChangeRequest::query()->create([
            'customer_id' => $customer->id,
            'company_id' => $customer->current_company_id ?? $customer->memberships()->latest('id')->value('company_id'),
            'type' => CompanyChangeType::Detach,
            'status' => CompanyChangeStatus::Approved,
            'customer_note' => filled($note) ? trim($note) : null,
            'admin_note' => 'Personal leave (no admin approval).',
            'reviewed_by_customer_id' => $customer->id,
            'reviewed_at' => now(),
        ]);
    }

    public function approve(CompanyChangeRequest $request, User|Customer $actor, ?string $adminNote = null): CompanyChangeRequest
    {
        if ($request->status !== CompanyChangeStatus::Pending) {
            throw ValidationException::withMessages(['status' => 'This request was already decided.']);
        }

        if ($request->type === CompanyChangeType::Attach && $actor instanceof User) {
            throw ValidationException::withMessages([
                'status' => 'Membership (attach) requests must be approved by the company owner in the partner portal.',
            ]);
        }

        if ($actor instanceof Customer) {
            $this->assertOwnerMayReview($actor, $request);
            if ($request->type !== CompanyChangeType::Attach) {
                throw ValidationException::withMessages([
                    'status' => 'Only membership (attach) requests can be decided by the company owner.',
                ]);
            }
        }

        return DB::transaction(function () use ($request, $actor, $adminNote) {
            $request->loadMissing(['customer', 'company']);
            $customer = $request->customer;
            $company = $request->company;

            if ($request->type === CompanyChangeType::Attach) {
                if ($this->membershipFor($customer, $company)) {
                    throw ValidationException::withMessages([
                        'status' => 'Customer is already a member of this company.',
                    ]);
                }
                if (! $company->hasOwner()) {
                    throw ValidationException::withMessages([
                        'status' => 'This company has no owner. Attach cannot be approved until an owner exists.',
                    ]);
                }
                $this->linkCustomer($customer, $company, CompanyRole::Member, switchTo: false);
            } else {
                if (! $this->membershipFor($customer, $company)) {
                    throw ValidationException::withMessages([
                        'status' => 'Customer is no longer linked to this company.',
                    ]);
                }
                // Temporarily set context so owner-leave checks use this company.
                $previous = $customer->current_company_id;
                $customer->forceFill(['current_company_id' => $company->id])->save();
                try {
                    $this->assertOwnerMayLeave($customer->fresh());
                    $this->unlinkCustomer($customer->fresh(), $company);
                } finally {
                    if ($previous && (int) $previous !== (int) $company->id) {
                        $customer->forceFill(['current_company_id' => $previous])->save();
                    }
                }
            }

            $request->fill([
                'status' => CompanyChangeStatus::Approved,
                'admin_note' => filled($adminNote) ? trim($adminNote) : null,
                'reviewed_by_user_id' => $actor instanceof User ? $actor->id : null,
                'reviewed_by_customer_id' => $actor instanceof Customer ? $actor->id : null,
                'reviewed_at' => now(),
            ])->save();

            $this->notifications->companyChangeDecided($request->fresh(['customer', 'company']));

            return $request->fresh(['customer', 'company', 'reviewer', 'customerReviewer']);
        });
    }

    public function reject(CompanyChangeRequest $request, User|Customer $actor, ?string $adminNote = null): CompanyChangeRequest
    {
        if ($request->status !== CompanyChangeStatus::Pending) {
            throw ValidationException::withMessages(['status' => 'This request was already decided.']);
        }

        if ($request->type === CompanyChangeType::Attach && $actor instanceof User) {
            throw ValidationException::withMessages([
                'status' => 'Membership (attach) requests must be rejected by the company owner in the partner portal.',
            ]);
        }

        if ($actor instanceof Customer) {
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
            'reviewed_by_customer_id' => $actor instanceof Customer ? $actor->id : null,
            'reviewed_at' => now(),
        ])->save();

        $this->notifications->companyChangeDecided($request->fresh(['customer', 'company']));

        return $request->fresh(['customer', 'company', 'reviewer', 'customerReviewer']);
    }

    /**
     * Pending attach requests waiting for this company owner.
     *
     * @return \Illuminate\Support\Collection<int, CompanyChangeRequest>
     */
    public function pendingMembershipRequestsForOwner(Customer $owner)
    {
        $this->assertIsActiveOwner($owner);

        return CompanyChangeRequest::query()
            ->with(['customer', 'company'])
            ->where('company_id', $owner->current_company_id)
            ->where('type', CompanyChangeType::Attach)
            ->where('status', CompanyChangeStatus::Pending)
            ->latest('id')
            ->get();
    }

    protected function assertOwnerMayReview(Customer $owner, CompanyChangeRequest $request): void
    {
        $this->assertIsActiveOwner($owner);

        if ((int) $owner->current_company_id !== (int) $request->company_id) {
            throw ValidationException::withMessages([
                'status' => 'Switch to that company first, then decide membership requests.',
            ]);
        }
    }

    protected function assertIsActiveOwner(Customer $customer): void
    {
        if ($this->roleOf($customer) !== CompanyRole::Owner || ! $customer->current_company_id) {
            throw ValidationException::withMessages([
                'company' => 'Only the company owner can manage membership requests.',
            ]);
        }

        if (! $customer->hasActiveCompanyMembership()) {
            throw ValidationException::withMessages([
                'company' => 'Your membership for this company is disabled.',
            ]);
        }

        $customer->loadMissing('company');
        if (! $customer->company?->isApproved()) {
            throw ValidationException::withMessages([
                'company' => 'Membership requests are available after admin approves your company profile.',
            ]);
        }
    }

    public function pendingRequestFor(Customer $customer): ?CompanyChangeRequest
    {
        return CompanyChangeRequest::query()
            ->with('company')
            ->where('customer_id', $customer->id)
            ->where('status', CompanyChangeStatus::Pending)
            ->latest('id')
            ->first();
    }

    public function linkCustomer(Customer $customer, Company $company, CompanyRole $role, bool $switchTo = true): void
    {
        if ($role === CompanyRole::Owner) {
            $existingOwnerId = CompanyMembership::query()
                ->where('company_id', $company->id)
                ->where('role', CompanyRole::Owner->value)
                ->value('customer_id');
            if ($existingOwnerId && (int) $existingOwnerId !== (int) $customer->id) {
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
                'customer_id' => $customer->id,
                'company_id' => $company->id,
            ],
            [
                'role' => $role->value,
                'is_active' => true,
            ],
        );

        if ($switchTo || ! $customer->current_company_id) {
            $customer->forceFill([
                'current_company_id' => $company->id,
                'profile_completed_at' => now(),
            ]);
            $this->syncCustomerCompanyFields($customer, $company);
        } else {
            $customer->forceFill(['profile_completed_at' => $customer->profile_completed_at ?? now()])->save();
        }
    }

    public function setMembershipActive(Company $company, Customer $member, bool $active, User $actor): Customer
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
                ->where('customer_id', '!=', $member->id)
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

    public function assertCanAccessCompany(Customer $customer): void
    {
        if (! $customer->current_company_id) {
            throw ValidationException::withMessages([
                'company' => 'Select or create a company before continuing.',
            ]);
        }

        if (! $customer->hasActiveCompanyMembership()) {
            throw ValidationException::withMessages([
                'company' => 'Your membership for this company is disabled. Contact an administrator.',
            ]);
        }

        $customer->loadMissing('company');
        if (! $customer->company?->isApproved()) {
            throw ValidationException::withMessages([
                'company' => 'Your company profile is waiting for admin approval. You can use services after it is approved.',
            ]);
        }
    }

    public function transferOwnership(Company $company, Customer $newOwner, User $actor): Company
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

            if ($currentOwnerMembership && (int) $currentOwnerMembership->customer_id === (int) $newOwner->id) {
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

    public function unlinkCustomer(Customer $customer, ?Company $company = null): void
    {
        $company ??= $customer->company;
        if (! $company) {
            return;
        }

        $customer->forceFill(['current_company_id' => $company->id])->save();
        $this->assertOwnerMayLeave($customer->fresh());

        CompanyMembership::query()
            ->where('customer_id', $customer->id)
            ->where('company_id', $company->id)
            ->delete();

        $this->switchToFallbackCompany($customer->fresh(), exceptCompanyId: $company->id);
    }

    public function switchCompany(Customer $customer, Company $company): Customer
    {
        $membership = $this->membershipFor($customer, $company);
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

        $customer->forceFill(['current_company_id' => $company->id]);
        $this->syncCustomerCompanyFields($customer, $company);

        return $customer->fresh(['company', 'memberships.company']);
    }

    public function syncCustomerCompanyFields(Customer $customer, Company $company): void
    {
        $customer->forceFill([
            'company_name' => $company->name,
            'company_tin' => $company->tin,
            'company_license_number' => $company->license_number,
            'company_phone' => $company->phone,
            'company_email' => $company->email,
            'company_address' => $company->address,
        ])->save();
    }

    public function syncAllMembersDenormalizedFields(Company $company): void
    {
        CompanyMembership::query()
            ->where('company_id', $company->id)
            ->with('customer')
            ->orderBy('id')
            ->each(function (CompanyMembership $membership) use ($company): void {
                $member = $membership->customer;
                if (! $member) {
                    return;
                }
                if ((int) $member->current_company_id === (int) $company->id) {
                    $this->syncCustomerCompanyFields($member, $company);
                }
            });
    }

    public function serializeCustomer(Customer $customer): array
    {
        $customer->loadMissing(['company', 'memberships.company']);
        $pending = $this->pendingRequestFor($customer);
        $company = $customer->company;
        $approvalStatus = $company?->approval_status instanceof CompanyApprovalStatus
            ? $company->approval_status
            : ($company ? CompanyApprovalStatus::tryFrom((string) $company->approval_status) : null);
        $companyApproved = $company?->isApproved() === true;
        $membershipActive = $customer->current_company_id
            ? $customer->hasActiveCompanyMembership()
            : null;
        $memberCount = $customer->current_company_id
            ? CompanyMembership::query()->where('company_id', $customer->current_company_id)->count()
            : 0;
        $isOwner = $this->roleOf($customer) === CompanyRole::Owner;
        $canEditCompany = $isOwner
            && $membershipActive
            && $company
            && ! $companyApproved;
        $canDetach = (bool) $customer->current_company_id
            && $membershipActive
            && $companyApproved
            && (! $isOwner || $memberCount <= 1);
        $pendingMembershipCount = ($isOwner && $membershipActive && $companyApproved && $customer->current_company_id)
            ? CompanyChangeRequest::query()
                ->where('company_id', $customer->current_company_id)
                ->where('type', CompanyChangeType::Attach)
                ->where('status', CompanyChangeStatus::Pending)
                ->count()
            : 0;

        $data = $customer->toArray();
        $data['company_id'] = $customer->current_company_id;
        $data['current_company_id'] = $customer->current_company_id;
        $data['company'] = ($company && $membershipActive !== false && $customer->current_company_id) ? [
            'public_id' => $company->public_id,
            'name' => $company->name,
            'tin' => $company->tin,
            'license_number' => $company->license_number,
            'phone' => $company->phone,
            'email' => $company->email,
            'address' => $company->address,
            'member_count' => $memberCount,
            'approval_status' => $approvalStatus?->value,
            'approval_note' => $company->approval_note,
            'is_approved' => $companyApproved,
        ] : null;
        $data['company_role'] = $customer->company_role;
        $data['company_membership_active'] = $membershipActive;
        $data['company_can_detach'] = $canDetach;
        $data['company_can_edit'] = $canEditCompany;
        $data['company_needs_ownership_transfer'] = $isOwner && $memberCount > 1 && $membershipActive && $companyApproved;
        $data['pending_membership_requests_count'] = $pendingMembershipCount;
        $data['profile_completed'] = $customer->profile_completed;
        $data['memberships'] = $customer->memberships
            ->map(function (CompanyMembership $m) use ($customer) {
                $c = $m->company;
                $role = $m->role instanceof CompanyRole ? $m->role->value : (string) $m->role;

                return [
                    'company_public_id' => $c?->public_id,
                    'company_name' => $c?->name,
                    'company_tin' => $c?->tin,
                    'company_license_number' => $c?->license_number,
                    'role' => $role,
                    'is_active' => (bool) $m->is_active,
                    'is_current' => (int) $m->company_id === (int) $customer->current_company_id,
                    'is_approved' => $c?->isApproved() === true,
                    'approval_status' => $c?->approval_status instanceof CompanyApprovalStatus
                        ? $c->approval_status->value
                        : ($c ? (string) $c->approval_status : null),
                ];
            })
            ->values()
            ->all();
        if ($membershipActive === false) {
            $data['company_name'] = null;
            $data['company_tin'] = null;
            $data['company_license_number'] = null;
            $data['company_phone'] = null;
            $data['company_email'] = null;
            $data['company_address'] = null;
            $data['profile_completed'] = false;
        }
        $data['pending_company_request'] = $pending ? [
            'public_id' => $pending->public_id,
            'type' => $pending->type->value,
            'status' => $pending->status->value,
            'customer_note' => $pending->customer_note,
            'company' => $pending->company ? [
                'public_id' => $pending->company->public_id,
                'name' => $pending->company->name,
                'tin' => $pending->company->tin,
                'license_number' => $pending->company->license_number,
            ] : null,
            'created_at' => optional($pending->created_at)?->toIso8601String(),
            'has_proposal' => $pending->hasProposal(),
            'has_letter' => $pending->hasLetter(),
        ] : null;

        return $data;
    }

    protected function roleOf(Customer $customer): ?CompanyRole
    {
        $membership = $customer->membershipForCurrentCompany();
        if (! $membership) {
            return null;
        }

        return $membership->role instanceof CompanyRole
            ? $membership->role
            : CompanyRole::tryFrom((string) $membership->role);
    }

    protected function membershipFor(Customer $customer, Company $company): ?CompanyMembership
    {
        return CompanyMembership::query()
            ->where('customer_id', $customer->id)
            ->where('company_id', $company->id)
            ->first();
    }

    protected function switchToFallbackCompany(Customer $customer, ?int $exceptCompanyId = null): void
    {
        $next = CompanyMembership::query()
            ->where('customer_id', $customer->id)
            ->where('is_active', true)
            ->when($exceptCompanyId, fn ($q) => $q->where('company_id', '!=', $exceptCompanyId))
            ->orderByRaw("CASE WHEN role = 'owner' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->first();

        if ($next) {
            $company = Company::query()->find($next->company_id);
            $customer->forceFill(['current_company_id' => $next->company_id]);
            if ($company) {
                $this->syncCustomerCompanyFields($customer, $company);
            } else {
                $customer->save();
            }

            return;
        }

        $customer->forceFill([
            'current_company_id' => null,
            'company_name' => null,
            'company_tin' => null,
            'company_license_number' => null,
            'company_phone' => null,
            'company_email' => null,
            'company_address' => null,
            'profile_completed_at' => null,
        ])->save();
    }

    /** Owner may leave only when they are the sole person on the company. */
    protected function assertOwnerMayLeave(Customer $customer): void
    {
        if ($this->roleOf($customer) !== CompanyRole::Owner || ! $customer->current_company_id) {
            return;
        }

        $others = CompanyMembership::query()
            ->where('company_id', $customer->current_company_id)
            ->where('customer_id', '!=', $customer->id)
            ->count();

        if ($others > 0) {
            throw ValidationException::withMessages([
                'company' => 'Company owner cannot leave while other members remain. Ask an admin to transfer ownership first.',
            ]);
        }
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

    protected function assertUniqueIdentity(string $tin, string $license, ?int $ignoreCompanyId = null): void
    {
        $tinQuery = Company::query()->where('tin', $tin);
        $licenseQuery = Company::query()->where('license_number', $license);
        if ($ignoreCompanyId) {
            $tinQuery->where('id', '!=', $ignoreCompanyId);
            $licenseQuery->where('id', '!=', $ignoreCompanyId);
        }

        if ($tinQuery->exists()) {
            throw ValidationException::withMessages([
                'company_tin' => 'A company with this TIN already exists. Use “Attach to existing company” instead.',
            ]);
        }

        if ($licenseQuery->exists()) {
            throw ValidationException::withMessages([
                'company_license_number' => 'A company with this license number already exists. Use “Attach to existing company” instead.',
            ]);
        }
    }

    /** @return array{disk: string, path: string, original_name: string, size: int} */
    protected function storePdf(Customer $customer, UploadedFile $file, string $kind): array
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
            'company-changes/'.$customer->id,
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
