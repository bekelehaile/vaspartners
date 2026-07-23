<?php

namespace App\Services;

use App\Enums\CompanyChangeStatus;
use App\Enums\CompanyChangeType;
use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\CompanyChangeRequest;
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
     * Create a new company and link the Fayda customer as owner (immediate, no admin approval).
     *
     * @param  array{company_name: string, company_tin: string, company_phone: string, company_email: string, company_address: string}  $data
     */
    public function createCompanyForCustomer(Customer $customer, array $data): Customer
    {
        if ($customer->company_id) {
            throw ValidationException::withMessages([
                'company' => 'You are already linked to a company. Request a detach first to move.',
            ]);
        }

        if ($this->pendingRequestFor($customer)) {
            throw ValidationException::withMessages([
                'company' => 'You already have a pending company request. Wait for admin decision.',
            ]);
        }

        $tin = $this->normalizeTin($data['company_tin']);
        if (Company::query()->where('tin', $tin)->exists()) {
            throw ValidationException::withMessages([
                'company_tin' => 'A company with this TIN already exists. Use “Attach to existing company” instead.',
            ]);
        }

        return DB::transaction(function () use ($customer, $data, $tin) {
            $company = Company::query()->create([
                'name' => trim($data['company_name']),
                'tin' => $tin,
                'phone' => trim($data['company_phone']),
                'email' => trim($data['company_email']),
                'address' => trim($data['company_address']),
                'is_active' => true,
                'created_by_customer_id' => $customer->id,
            ]);

            $this->linkCustomer($customer, $company, CompanyRole::Owner);
            $fresh = $customer->fresh(['company']);
            $this->notifications->profileCompleted($fresh);

            return $fresh;
        });
    }

    /**
     * Update company details when the linked customer is the owner (or sole member).
     *
     * @param  array{company_name: string, company_tin: string, company_phone: string, company_email: string, company_address: string}  $data
     */
    public function updateOwnCompany(Customer $customer, array $data): Customer
    {
        if (! $customer->company_id) {
            return $this->createCompanyForCustomer($customer, $data);
        }

        $company = $customer->company;
        if (! $company) {
            throw ValidationException::withMessages(['company' => 'Company not found.']);
        }

        $role = $customer->company_role instanceof CompanyRole
            ? $customer->company_role
            : CompanyRole::tryFrom((string) $customer->company_role);

        if ($role !== CompanyRole::Owner) {
            throw ValidationException::withMessages([
                'company' => 'Only the company owner can update organisation details.',
            ]);
        }

        if ($customer->company_membership_active === false) {
            throw ValidationException::withMessages([
                'company' => 'Your membership for this company is disabled. Contact an administrator.',
            ]);
        }

        $tin = $this->normalizeTin($data['company_tin']);
        if (Company::query()->where('tin', $tin)->where('id', '!=', $company->id)->exists()) {
            throw ValidationException::withMessages([
                'company_tin' => 'Another company already uses this TIN.',
            ]);
        }

        return DB::transaction(function () use ($customer, $company, $data, $tin) {
            $company->fill([
                'name' => trim($data['company_name']),
                'tin' => $tin,
                'phone' => trim($data['company_phone']),
                'email' => trim($data['company_email']),
                'address' => trim($data['company_address']),
            ])->save();

            $this->syncCustomerCompanyFields($customer, $company);

            return $customer->fresh(['company']);
        });
    }

    public function lookupByTin(string $tin): ?Company
    {
        $tin = $this->normalizeTin($tin);
        if ($tin === '') {
            return null;
        }

        return Company::query()->where('tin', $tin)->where('is_active', true)->first();
    }

    public function requestAttach(Customer $customer, string $tin, ?string $note = null): CompanyChangeRequest
    {
        if ($customer->company_id) {
            throw ValidationException::withMessages([
                'company_tin' => 'You are already linked to a company. Request detach before attaching to another.',
            ]);
        }

        if ($this->pendingRequestFor($customer)) {
            throw ValidationException::withMessages([
                'company_tin' => 'You already have a pending company request.',
            ]);
        }

        $company = $this->lookupByTin($tin);
        if (! $company) {
            throw ValidationException::withMessages([
                'company_tin' => 'No active company found for this TIN. Create a new company instead.',
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

    public function requestDetach(
        Customer $customer,
        ?string $note,
        UploadedFile $proposal,
        UploadedFile $letter,
    ): CompanyChangeRequest {
        if (! $customer->company_id) {
            throw ValidationException::withMessages([
                'company' => 'You are not linked to a company.',
            ]);
        }

        if ($this->pendingRequestFor($customer)) {
            throw ValidationException::withMessages([
                'company' => 'You already have a pending company request.',
            ]);
        }

        $this->assertOwnerMayLeave($customer);

        $proposalMeta = $this->storePdf($customer, $proposal, 'proposal');
        $letterMeta = $this->storePdf($customer, $letter, 'letter');

        $request = CompanyChangeRequest::query()->create([
            'customer_id' => $customer->id,
            'company_id' => $customer->company_id,
            'type' => CompanyChangeType::Detach,
            'status' => CompanyChangeStatus::Pending,
            'customer_note' => filled($note) ? trim($note) : null,
            'proposal_disk' => $proposalMeta['disk'],
            'proposal_path' => $proposalMeta['path'],
            'proposal_original_name' => $proposalMeta['original_name'],
            'proposal_size_bytes' => $proposalMeta['size'],
            'letter_disk' => $letterMeta['disk'],
            'letter_path' => $letterMeta['path'],
            'letter_original_name' => $letterMeta['original_name'],
            'letter_size_bytes' => $letterMeta['size'],
        ]);

        $this->notifications->companyChangeRequested($request);

        return $request->load(['company', 'customer']);
    }

    public function approve(CompanyChangeRequest $request, User $admin, ?string $adminNote = null): CompanyChangeRequest
    {
        if ($request->status !== CompanyChangeStatus::Pending) {
            throw ValidationException::withMessages(['status' => 'This request was already decided.']);
        }

        return DB::transaction(function () use ($request, $admin, $adminNote) {
            $request->loadMissing(['customer', 'company']);
            $customer = $request->customer;
            $company = $request->company;

            if ($request->type === CompanyChangeType::Attach) {
                if ($customer->company_id) {
                    throw ValidationException::withMessages([
                        'status' => 'Customer is already linked to a company.',
                    ]);
                }
                if (! $company->hasOwner()) {
                    throw ValidationException::withMessages([
                        'status' => 'This company has no owner. Attach cannot be approved until an owner exists.',
                    ]);
                }
                $this->linkCustomer($customer, $company, CompanyRole::Member);
            } else {
                if ((int) $customer->company_id !== (int) $company->id) {
                    throw ValidationException::withMessages([
                        'status' => 'Customer is no longer linked to this company.',
                    ]);
                }
                $this->assertOwnerMayLeave($customer);
                $this->unlinkCustomer($customer);
            }

            $request->fill([
                'status' => CompanyChangeStatus::Approved,
                'admin_note' => filled($adminNote) ? trim($adminNote) : null,
                'reviewed_by_user_id' => $admin->id,
                'reviewed_at' => now(),
            ])->save();

            $this->notifications->companyChangeDecided($request->fresh(['customer', 'company']));

            return $request->fresh(['customer', 'company', 'reviewer']);
        });
    }

    public function reject(CompanyChangeRequest $request, User $admin, ?string $adminNote = null): CompanyChangeRequest
    {
        if ($request->status !== CompanyChangeStatus::Pending) {
            throw ValidationException::withMessages(['status' => 'This request was already decided.']);
        }

        $request->fill([
            'status' => CompanyChangeStatus::Rejected,
            'admin_note' => filled($adminNote) ? trim($adminNote) : null,
            'reviewed_by_user_id' => $admin->id,
            'reviewed_at' => now(),
        ])->save();

        $this->notifications->companyChangeDecided($request->fresh(['customer', 'company']));

        return $request->fresh(['customer', 'company', 'reviewer']);
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

    public function linkCustomer(Customer $customer, Company $company, CompanyRole $role): void
    {
        if ($role === CompanyRole::Owner) {
            $existingOwnerId = $company->owner()->value('id');
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

        $customer->forceFill([
            'company_id' => $company->id,
            'company_role' => $role->value,
            'company_membership_active' => true,
            'profile_completed_at' => now(),
        ]);
        $this->syncCustomerCompanyFields($customer, $company);
    }

    /**
     * Enable or disable a member's access to company info and company subscriptions.
     * Disabled members stay linked but cannot use portal company/services features.
     */
    public function setMembershipActive(Company $company, Customer $member, bool $active, User $actor): Customer
    {
        if ((int) $member->company_id !== (int) $company->id) {
            throw ValidationException::withMessages([
                'member' => 'This partner is not a member of this company.',
            ]);
        }

        if ($this->roleOf($member) === CompanyRole::Owner && ! $active) {
            $otherActive = Customer::query()
                ->where('company_id', $company->id)
                ->where('id', '!=', $member->id)
                ->where('company_membership_active', true)
                ->exists();

            if ($otherActive) {
                throw ValidationException::withMessages([
                    'member' => 'Cannot disable the owner while other active members remain. Transfer ownership first.',
                ]);
            }
        }

        $member->forceFill(['company_membership_active' => $active])->save();

        return $member->fresh(['company']);
    }

    /**
     * @throws ValidationException
     */
    public function assertCanAccessCompany(Customer $customer): void
    {
        if (! $customer->company_id) {
            throw ValidationException::withMessages([
                'company' => 'Complete your company profile before continuing.',
            ]);
        }

        if ($customer->company_membership_active === false) {
            throw ValidationException::withMessages([
                'company' => 'Your membership for this company is disabled. Contact an administrator.',
            ]);
        }
    }

    /**
     * Transfer ownership to another member of the same company.
     * Company always keeps exactly one owner.
     */
    public function transferOwnership(Company $company, Customer $newOwner, User $actor): Company
    {
        return DB::transaction(function () use ($company, $newOwner) {
            if ((int) $newOwner->company_id !== (int) $company->id) {
                throw ValidationException::withMessages([
                    'owner' => 'The new owner must already be a member of this company.',
                ]);
            }

            $currentOwner = $company->owner()->first();
            if ($currentOwner && (int) $currentOwner->id === (int) $newOwner->id) {
                return $company->fresh(['owner', 'members']);
            }

            if ($currentOwner) {
                $currentOwner->forceFill(['company_role' => CompanyRole::Member->value])->save();
            }

            $newOwner->forceFill(['company_role' => CompanyRole::Owner->value])->save();

            return $company->fresh(['owner', 'members']);
        });
    }

    public function unlinkCustomer(Customer $customer): void
    {
        $this->assertOwnerMayLeave($customer);

        $customer->forceFill([
            'company_id' => null,
            'company_role' => null,
            'company_membership_active' => true,
            'company_name' => null,
            'company_tin' => null,
            'company_phone' => null,
            'company_email' => null,
            'company_address' => null,
            'profile_completed_at' => null,
        ])->save();
    }

    public function syncCustomerCompanyFields(Customer $customer, Company $company): void
    {
        $customer->forceFill([
            'company_name' => $company->name,
            'company_tin' => $company->tin,
            'company_phone' => $company->phone,
            'company_email' => $company->email,
            'company_address' => $company->address,
        ])->save();
    }

    public function serializeCustomer(Customer $customer): array
    {
        $customer->loadMissing('company');
        $pending = $this->pendingRequestFor($customer);
        $membershipActive = $customer->company_id
            ? $customer->company_membership_active !== false
            : null;
        $memberCount = $customer->company_id
            ? Customer::query()->where('company_id', $customer->company_id)->count()
            : 0;
        $isOwner = $this->roleOf($customer) === CompanyRole::Owner;
        $canDetach = (bool) $customer->company_id
            && $membershipActive
            && (! $isOwner || $memberCount <= 1);

        $data = $customer->toArray();
        $data['company'] = ($customer->company && $membershipActive) ? [
            'public_id' => $customer->company->public_id,
            'name' => $customer->company->name,
            'tin' => $customer->company->tin,
            'phone' => $customer->company->phone,
            'email' => $customer->company->email,
            'address' => $customer->company->address,
            'member_count' => $memberCount,
        ] : null;
        $data['company_role'] = $customer->company_role;
        $data['company_membership_active'] = $membershipActive;
        $data['company_can_detach'] = $canDetach;
        $data['company_needs_ownership_transfer'] = $isOwner && $memberCount > 1 && $membershipActive;
        // Hide denormalized company fields when membership is disabled.
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
            'customer_note' => $pending->customer_note,
            'company' => $pending->company ? [
                'public_id' => $pending->company->public_id,
                'name' => $pending->company->name,
                'tin' => $pending->company->tin,
            ] : null,
            'created_at' => optional($pending->created_at)?->toIso8601String(),
            'has_proposal' => $pending->hasProposal(),
            'has_letter' => $pending->hasLetter(),
        ] : null;

        return $data;
    }

    protected function roleOf(Customer $customer): ?CompanyRole
    {
        if ($customer->company_role instanceof CompanyRole) {
            return $customer->company_role;
        }

        return CompanyRole::tryFrom((string) $customer->company_role);
    }

    /** Owner may leave only when they are the sole person on the company. */
    protected function assertOwnerMayLeave(Customer $customer): void
    {
        if ($this->roleOf($customer) !== CompanyRole::Owner || ! $customer->company_id) {
            return;
        }

        $others = Customer::query()
            ->where('company_id', $customer->company_id)
            ->where('id', '!=', $customer->id)
            ->count();

        if ($others > 0) {
            throw ValidationException::withMessages([
                'company' => 'Company owner cannot leave while other members remain. Ask an admin to transfer ownership first.',
            ]);
        }
    }

    protected function normalizeTin(string $tin): string
    {
        return strtoupper(preg_replace('/\s+/', '', trim($tin)) ?? '');
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
