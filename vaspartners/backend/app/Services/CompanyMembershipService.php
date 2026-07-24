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
     * Personal leave: partner detaches themselves from the current company immediately.
     * No admin approval or PDFs. Joining still requires company-owner approval.
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

        if ($this->pendingRequestFor($customer)) {
            throw ValidationException::withMessages([
                'company' => 'You have a pending membership request. Wait for a decision or cancel it first.',
            ]);
        }

        $this->assertOwnerMayLeave($customer);

        $company = $customer->company;
        if (! $company) {
            throw ValidationException::withMessages(['company' => 'Company not found.']);
        }

        $owner = $company->ownerCustomer();
        $companyId = $company->id;

        return DB::transaction(function () use ($customer, $company, $companyId, $owner, $note) {
            CompanyChangeRequest::query()->create([
                'customer_id' => $customer->id,
                'company_id' => $companyId,
                'type' => CompanyChangeType::Detach,
                'status' => CompanyChangeStatus::Approved,
                'customer_note' => filled($note) ? trim($note) : null,
                'admin_note' => 'Personal leave — no admin approval required.',
                'reviewed_by_customer_id' => $customer->id,
                'reviewed_at' => now(),
            ]);

            $this->unlinkCustomer($customer, $company);

            if ($owner && (int) $owner->id !== (int) $customer->id) {
                DB::afterCommit(function () use ($company, $owner, $customer, $note) {
                    $this->notifications->memberLeftCompany(
                        $company->fresh(),
                        $owner->fresh(),
                        $customer->fresh(),
                        $note,
                    );
                });
            }

            return $customer->fresh(['company', 'memberships.company']);
        });
    }

    public function approve(CompanyChangeRequest $request, User|Customer $actor, ?string $adminNote = null): CompanyChangeRequest
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

        $this->notifications->companyChangeDecided($request->fresh(['customer', 'company', 'targetCustomer']));

        return $request->fresh(['customer', 'company', 'reviewer', 'customerReviewer', 'targetCustomer']);
    }

    /**
     * Owner requests to transfer ownership to another active member (letter PDF required).
     * Admin must approve in Filament.
     */
    public function requestOwnershipTransfer(
        Customer $owner,
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

        $newOwner = Customer::query()->where('public_id', $newOwnerPublicId)->first();
        if (! $newOwner) {
            throw ValidationException::withMessages([
                'target_customer' => 'Selected partner was not found.',
            ]);
        }

        if ((int) $newOwner->id === (int) $owner->id) {
            throw ValidationException::withMessages([
                'target_customer' => 'Choose a different partner as the new owner.',
            ]);
        }

        $membership = $this->membershipFor($newOwner, $company);
        if (! $membership || ! $membership->is_active) {
            throw ValidationException::withMessages([
                'target_customer' => 'The new owner must be an active member of this company.',
            ]);
        }

        $letterMeta = $this->storePdf($owner, $letter, 'letter');

        $request = CompanyChangeRequest::query()->create([
            'customer_id' => $owner->id,
            'company_id' => $company->id,
            'target_customer_id' => $newOwner->id,
            'type' => CompanyChangeType::TransferOwnership,
            'status' => CompanyChangeStatus::Pending,
            'customer_note' => filled($note) ? trim($note) : null,
            'letter_disk' => $letterMeta['disk'],
            'letter_path' => $letterMeta['path'],
            'letter_original_name' => $letterMeta['original_name'],
            'letter_size_bytes' => $letterMeta['size'],
        ]);

        $this->notifications->companyChangeRequested($request->load(['company', 'customer', 'targetCustomer']));

        return $request;
    }

    protected function approveOwnershipTransfer(CompanyChangeRequest $request, User $admin, ?string $adminNote = null): CompanyChangeRequest
    {
        return DB::transaction(function () use ($request, $admin, $adminNote) {
            $request->loadMissing(['customer', 'company', 'targetCustomer']);
            $company = $request->company;
            $currentOwner = $request->customer;
            $newOwner = $request->targetCustomer;

            if (! $company || ! $currentOwner || ! $newOwner) {
                throw ValidationException::withMessages(['status' => 'Transfer request is incomplete.']);
            }

            $ownerMembership = CompanyMembership::query()
                ->where('company_id', $company->id)
                ->where('customer_id', $currentOwner->id)
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
                'reviewed_by_customer_id' => null,
                'reviewed_at' => now(),
            ])->save();

            $this->notifications->companyChangeDecided($request->fresh(['customer', 'company', 'targetCustomer']));

            return $request->fresh(['customer', 'company', 'reviewer', 'targetCustomer']);
        });
    }

    /**
     * Members of the current company (Fayda identity fields for the portal roster).
     * Any active member of an approved company may view the list.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    public function listCurrentCompanyMembers(Customer $viewer)
    {
        $this->assertCanAccessCompany($viewer);

        return CompanyMembership::query()
            ->with('customer')
            ->where('company_id', $viewer->current_company_id)
            ->orderByRaw("CASE WHEN role = 'owner' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->get()
            ->map(function (CompanyMembership $m) {
                $c = $m->customer;
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
                ];
            })
            ->values();
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

    /**
     * Shared inbox: requests this partner submitted + membership joins they must review as owner.
     *
     * @return array{submitted: list<array<string, mixed>>, to_review: list<array<string, mixed>>, summary: array<string, int>}
     */
    public function companyRequestsInbox(Customer $customer): array
    {
        $ownedCompanyIds = CompanyMembership::query()
            ->where('customer_id', $customer->id)
            ->where('role', CompanyRole::Owner->value)
            ->where('is_active', true)
            ->pluck('company_id');

        $submittedChanges = CompanyChangeRequest::query()
            ->with(['customer', 'company', 'targetCustomer', 'reviewer', 'customerReviewer'])
            ->where('customer_id', $customer->id)
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (CompanyChangeRequest $r) => $this->serializeRequestCard($r, $customer, 'submitted'))
            ->all();

        $profileCards = Company::query()
            ->where(function ($q) use ($customer, $ownedCompanyIds) {
                $q->where('created_by_customer_id', $customer->id);
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
        if ($ownedCompanyIds->isNotEmpty()) {
            $toReview = CompanyChangeRequest::query()
                ->with(['customer', 'company', 'targetCustomer', 'reviewer', 'customerReviewer'])
                ->whereIn('company_id', $ownedCompanyIds)
                ->where('type', CompanyChangeType::Attach)
                ->where('status', CompanyChangeStatus::Pending)
                ->latest('id')
                ->limit(50)
                ->get()
                ->map(fn (CompanyChangeRequest $r) => $this->serializeRequestCard($r, $customer, 'to_review'))
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

    public function cancelOwnRequest(Customer $customer, CompanyChangeRequest $request): CompanyChangeRequest
    {
        if ((int) $request->customer_id !== (int) $customer->id) {
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
            'reviewed_by_customer_id' => $customer->id,
            'reviewed_by_user_id' => null,
            'reviewed_at' => now(),
        ])->save();

        return $request->fresh(['customer', 'company', 'targetCustomer']);
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeRequestCard(
        CompanyChangeRequest $request,
        ?Customer $viewer = null,
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
            && $this->customerOwnsCompany($viewer, (int) $request->company_id);

        $canCancel = $viewer
            && $direction === 'submitted'
            && $status === CompanyChangeStatus::Pending->value
            && (int) $request->customer_id === (int) $viewer->id
            && $type !== CompanyChangeType::Detach->value;

        return [
            'kind' => 'membership_change',
            'public_id' => $request->public_id,
            'type' => $type,
            'status' => $status,
            'direction' => $direction,
            'awaiting' => $awaiting,
            'customer_note' => $request->customer_note,
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
                'license_number' => $request->company->license_number,
            ] : null,
            'applicant' => $request->customer ? [
                'public_id' => $request->customer->public_id,
                'name' => $request->customer->name,
                'phone_number' => $request->customer->phone_number,
                'email' => $request->customer->email,
            ] : null,
            'target_customer' => $request->targetCustomer ? [
                'public_id' => $request->targetCustomer->public_id,
                'name' => $request->targetCustomer->name,
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
            'customer_note' => null,
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
                'license_number' => $company->license_number,
            ],
            'applicant' => null,
            'target_customer' => null,
        ];
    }

    protected function customerOwnsCompany(Customer $customer, int $companyId): bool
    {
        return CompanyMembership::query()
            ->where('customer_id', $customer->id)
            ->where('company_id', $companyId)
            ->where('role', CompanyRole::Owner->value)
            ->where('is_active', true)
            ->exists();
    }

    protected function assertOwnerMayReview(Customer $owner, CompanyChangeRequest $request): void
    {
        if (! $this->customerOwnsCompany($owner, (int) $request->company_id)) {
            throw ValidationException::withMessages([
                'status' => 'Only the company owner can decide this membership request.',
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

    /**
     * After Fayda login: claim exactly one ownerless approved company for this partner.
     * Prefer legacy_mvas_client_id match (migrated dump), else unique phone last-9 match.
     * Orphan / ambiguous companies stay ownerless for admin Assign owner.
     */
    public function tryAutoClaimMigratedCompanyByPhone(Customer $customer): ?Company
    {
        if ($customer->memberships()->exists() || filled($customer->current_company_id)) {
            return null;
        }

        $company = null;

        if (filled($customer->legacy_mvas_client_id)) {
            $company = Company::query()
                ->where('legacy_mvas_client_id', $customer->legacy_mvas_client_id)
                ->where('is_active', true)
                ->where('approval_status', CompanyApprovalStatus::Approved->value)
                ->whereDoesntHave('memberships', function ($query) {
                    $query->where('role', CompanyRole::Owner->value);
                })
                ->first();
        }

        if (! $company) {
            $last9 = app(SmsService::class)->normalizePhone((string) $customer->phone_number);
            if ($last9 === '' || ! preg_match('/^\d{9}$/', $last9)) {
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
                        'customer_id' => $customer->id,
                        'phone_last9' => $last9,
                        'company_ids' => $candidates->pluck('id')->all(),
                    ]);
                }

                return null;
            }

            $company = $candidates->first();
        }

        return DB::transaction(function () use ($customer, $company) {
            if ($company->hasOwner()) {
                return null;
            }

            $this->linkCustomer($customer, $company, CompanyRole::Owner, switchTo: true);

            if (! filled($company->created_by_customer_id)) {
                $company->forceFill([
                    'created_by_customer_id' => $customer->id,
                ])->save();
            }

            Log::info('Fayda auto-claimed migrated company', [
                'customer_id' => $customer->id,
                'company_id' => $company->id,
                'legacy_mvas_client_id' => $company->legacy_mvas_client_id,
                'company_tin' => $company->tin,
            ]);

            return $company->fresh();
        });
    }

    /**
     * Admin verification: assign an owner to an orphan (ownerless) company.
     */
    public function adminAssignOwner(Company $company, Customer $customer, User $admin, ?string $note = null): Company
    {
        if ($company->hasOwner()) {
            throw ValidationException::withMessages([
                'owner' => 'This company already has an owner.',
            ]);
        }

        if (! $customer->is_active || $customer->is_banned) {
            throw ValidationException::withMessages([
                'owner' => 'Cannot assign an inactive or banned partner as owner.',
            ]);
        }

        return DB::transaction(function () use ($company, $customer, $admin, $note) {
            $this->linkCustomer($customer, $company, CompanyRole::Owner, switchTo: true);

            $company->forceFill([
                'created_by_customer_id' => $company->created_by_customer_id ?: $customer->id,
                'approval_status' => CompanyApprovalStatus::Approved,
                'is_active' => true,
                'approved_by_user_id' => $admin->id,
                'approved_at' => $company->approved_at ?? now(),
                'approval_note' => trim((string) ($note ?: $company->approval_note ?: 'Owner assigned by admin after verification.')),
            ])->save();

            Log::info('Admin assigned owner to orphan company', [
                'company_id' => $company->id,
                'customer_id' => $customer->id,
                'admin_id' => $admin->id,
            ]);

            return $company->fresh(['memberships.customer']);
        });
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
                'company' => 'Create a company with a unique TIN (or join an approved company) before using VAS services.',
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
                'company' => 'Services are locked until an administrator approves your company profile for this TIN. Complete company details and wait for approval.',
            ]);
        }

        if (! filled($customer->company->tin)) {
            throw ValidationException::withMessages([
                'company' => 'A valid company TIN is required before using VAS services.',
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
        if ($pending) {
            $pending->loadMissing(['company', 'targetCustomer']);
        }
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
            && ! $isOwner;
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
        // Owner must transfer ownership (admin-approved) before they can leave.
        $data['company_needs_ownership_transfer'] = $isOwner && $membershipActive && (bool) $customer->current_company_id;
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
            'target_customer' => $pending->targetCustomer ? [
                'public_id' => $pending->targetCustomer->public_id,
                'name' => $pending->targetCustomer->name,
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

    /**
     * Company owner cannot leave while they are still the owner.
     * They must request an ownership transfer (letter + admin approval) first,
     * then leave as a member.
     */
    protected function assertOwnerMayLeave(Customer $customer): void
    {
        if ($this->roleOf($customer) !== CompanyRole::Owner || ! $customer->current_company_id) {
            return;
        }

        throw ValidationException::withMessages([
            'company' => 'Company owner cannot leave. Transfer ownership to another active member first (letter required; admin must approve). After you are no longer the owner, you can leave as a member.',
        ]);
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
                'company_tin' => 'This TIN is already registered to another company. TINs are unique — use “Join existing company” with the matching license number, or contact an administrator.',
            ]);
        }

        if ($licenseQuery->exists()) {
            throw ValidationException::withMessages([
                'company_license_number' => 'This license number is already registered to another company. Use “Join existing company” with the matching TIN, or contact an administrator.',
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
