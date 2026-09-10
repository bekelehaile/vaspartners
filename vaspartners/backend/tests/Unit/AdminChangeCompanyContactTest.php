<?php

namespace Tests\Unit;

use App\Enums\CompanyApprovalStatus;
use App\Enums\CompanyRole;
use App\Enums\ErcaNameStatus;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\CompanyMembershipAuditLog;
use App\Models\Contact;
use App\Models\User;
use App\Services\CompanyMembershipService;
use App\Services\Crm\CrmCustomerLookupService;
use App\Support\ErcaTinWriteGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

/**
 * Uses the application database inside a rolled-back transaction.
 * Full migrate:fresh is unsuitable here (Postgres-only SQL + large seed migrations).
 */
class AdminChangeCompanyContactTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        Mockery::close();
        ErcaTinWriteGuard::reset();
        parent::tearDown();
    }

    public function test_verified_company_crm_hit_transfers_owner_and_preserves_phones(): void
    {
        $admin = $this->makeAdmin();
        [$company, $oldOwner] = $this->makeVerifiedCompanyWithOwner(
            claimPhone: '911111111',
            revenuePhone: '922222222',
            ercaPhone: '933333333',
        );

        $this->mockCrmFound('944444444', 'New CRM Owner');

        $result = app(CompanyMembershipService::class)->adminChangeCompanyContact(
            $company,
            '944444444',
            $admin,
            'Support ticket #1',
        );

        $result->refresh();
        $newOwner = Contact::query()->where('phone_number', '944444444')->first();

        $this->assertNotNull($newOwner);
        $this->assertSame('New CRM Owner', $newOwner->name);
        $this->assertSame('crm', $newOwner->identity_verified_via);
        $this->assertSame($newOwner->id, $result->ownerContact()?->id);
        $this->assertSame('944444444', $result->claimPhone());
        $this->assertSame('922222222', $result->revenuePhone());
        $this->assertSame('933333333', $result->ercaPhone());

        $oldMembership = CompanyMembership::query()
            ->where('company_id', $company->id)
            ->where('contact_id', $oldOwner->id)
            ->first();
        $this->assertNotNull($oldMembership);
        $this->assertFalse((bool) $oldMembership->is_active);
        $this->assertSame(CompanyRole::Member, $oldMembership->role);

        $newMembership = CompanyMembership::query()
            ->where('company_id', $company->id)
            ->where('contact_id', $newOwner->id)
            ->first();
        $this->assertNotNull($newMembership);
        $this->assertTrue((bool) $newMembership->is_active);
        $this->assertSame(CompanyRole::Owner, $newMembership->role);

        $this->assertTrue(
            CompanyMembershipAuditLog::query()
                ->where('company_id', $company->id)
                ->where('action', 'contact_changed')
                ->exists()
        );
    }

    public function test_crm_miss_blocks_without_mutation(): void
    {
        $admin = $this->makeAdmin();
        [$company, $oldOwner] = $this->makeVerifiedCompanyWithOwner(
            claimPhone: '911111111',
            revenuePhone: '922222222',
            ercaPhone: '933333333',
        );

        $this->mockCrmNotFound('955555555');

        try {
            app(CompanyMembershipService::class)->adminChangeCompanyContact(
                $company,
                '955555555',
                $admin,
            );
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('phone', $e->errors());
        }

        $company->refresh();
        $this->assertSame($oldOwner->id, $company->ownerContact()?->id);
        $this->assertSame('911111111', $company->claimPhone());
        $this->assertSame('922222222', $company->revenuePhone());
        $this->assertSame('933333333', $company->ercaPhone());
        $this->assertNull(Contact::query()->where('phone_number', '955555555')->first());
    }

    public function test_crm_unavailable_blocks_without_mutation(): void
    {
        $admin = $this->makeAdmin();
        [$company, $oldOwner] = $this->makeVerifiedCompanyWithOwner(
            claimPhone: '911111111',
            revenuePhone: '922222222',
            ercaPhone: '933333333',
        );

        $crm = Mockery::mock(CrmCustomerLookupService::class);
        $crm->shouldReceive('lookupByPhone')->once()->with('966666666')->andReturn(null);
        $this->app->instance(CrmCustomerLookupService::class, $crm);

        try {
            app(CompanyMembershipService::class)->adminChangeCompanyContact(
                $company,
                '966666666',
                $admin,
            );
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('phone', $e->errors());
        }

        $company->refresh();
        $this->assertSame($oldOwner->id, $company->ownerContact()?->id);
        $this->assertSame('911111111', $company->claimPhone());
    }

    public function test_unverified_company_is_rejected(): void
    {
        $admin = $this->makeAdmin();
        [$company] = $this->makeVerifiedCompanyWithOwner(
            claimPhone: '911111111',
            revenuePhone: '922222222',
            ercaPhone: '933333333',
            ercaVerified: false,
        );

        try {
            app(CompanyMembershipService::class)->adminChangeCompanyContact(
                $company,
                '944444444',
                $admin,
            );
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('company', $e->errors());
        }
    }

    protected function makeAdmin(): User
    {
        return User::query()->create([
            'name' => 'Admin',
            'username' => 'admin_chg_'.uniqid(),
            'email' => 'admin_chg_'.uniqid().'@example.com',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
    }

    /**
     * @return array{0: Company, 1: Contact}
     */
    protected function makeVerifiedCompanyWithOwner(
        string $claimPhone,
        string $revenuePhone,
        string $ercaPhone,
        bool $ercaVerified = true,
    ): array {
        $suffix = substr(uniqid(), -6);
        $claimPhone = $this->uniquePhone($claimPhone, $suffix);
        $revenuePhone = $this->uniquePhone($revenuePhone, $suffix);
        $ercaPhone = $this->uniquePhone($ercaPhone, $suffix);

        $owner = new Contact;
        $owner->syncFromFayda([
            'sub' => 'otp-'.$claimPhone.'-'.$suffix,
            'name' => 'Old Owner '.$suffix,
            'phone_number' => $claimPhone,
            'identification_type' => '2',
            'identification_number' => 'otp-'.$claimPhone.'-'.$suffix,
            'nationality' => 'Ethiopian',
        ]);
        $owner->forceFill(['is_active' => true])->save();

        // Valid 10-digit TIN unique per run.
        $tin = str_pad((string) random_int(1000000000, 1999999999), 10, '0', STR_PAD_LEFT);

        $company = ErcaTinWriteGuard::without(function () use (
            $tin,
            $claimPhone,
            $revenuePhone,
            $ercaPhone,
            $ercaVerified,
            $owner,
            $suffix,
        ) {
            $company = new Company;
            $company->forceFill([
                'name' => 'Test Co '.$suffix,
                'legal_name' => 'Test Co PLC '.$suffix,
                'tin' => $tin,
                'tin_validated' => $ercaVerified,
                'erca_tin_verified' => $ercaVerified,
                'erca_verified_at' => $ercaVerified ? now() : null,
                'erca_name_status' => ErcaNameStatus::Matched->value,
                'claim_phone' => $claimPhone,
                'phone' => $claimPhone,
                'revenue_phone' => $revenuePhone,
                'erca_phone' => $ercaPhone,
                'is_active' => true,
                'approval_status' => CompanyApprovalStatus::Approved->value,
                'created_by_contact_id' => $owner->id,
            ])->save();

            return $company;
        });

        CompanyMembership::query()->create([
            'contact_id' => $owner->id,
            'company_id' => $company->id,
            'role' => CompanyRole::Owner->value,
            'is_active' => true,
            'permissions' => null,
        ]);

        $owner->forceFill(['current_company_id' => $company->id])->save();

        return [$company->fresh(), $owner->fresh()];
    }

    protected function uniquePhone(string $baseNine, string $suffix): string
    {
        // Keep Ethio telecom mobile shape (9xxxxxxxx / 8xxxxxxxx) while staying unique.
        $digits = preg_replace('/\D+/', '', $baseNine) ?: '911111111';
        $digits = substr($digits, -9);
        $tail = substr(preg_replace('/\D+/', '', $suffix) ?: '1', -4);
        $prefix = substr($digits, 0, 1); // 9 or 8
        $mid = substr($digits, 1, 4);

        return $prefix.$mid.str_pad($tail, 4, '0', STR_PAD_LEFT);
    }

    protected function mockCrmFound(string $phone, string $name): void
    {
        // Resolve after uniquePhone mutation in helpers — callers pass intended new phone base.
        $crm = Mockery::mock(CrmCustomerLookupService::class);
        $crm->shouldReceive('lookupByPhone')
            ->once()
            ->andReturnUsing(function (string $normalized) use ($name) {
                return [
                    'found' => true,
                    'customer_name' => $name,
                    'phone' => $normalized,
                    'email' => null,
                    'gender' => null,
                    'nationality' => 'Ethiopian',
                    'birthdate' => null,
                    'identification_type' => '2',
                    'identification_number' => 'crm-'.$normalized,
                    'customer_code' => null,
                    'raw' => ['customer_name' => $name],
                ];
            });
        $this->app->instance(CrmCustomerLookupService::class, $crm);
    }

    protected function mockCrmNotFound(string $phone): void
    {
        $crm = Mockery::mock(CrmCustomerLookupService::class);
        $crm->shouldReceive('lookupByPhone')
            ->once()
            ->andReturnUsing(function (string $normalized) {
                return [
                    'found' => false,
                    'customer_name' => null,
                    'phone' => $normalized,
                    'email' => null,
                    'gender' => null,
                    'nationality' => null,
                    'birthdate' => null,
                    'identification_type' => null,
                    'identification_number' => null,
                    'customer_code' => null,
                    'raw' => [],
                ];
            });
        $this->app->instance(CrmCustomerLookupService::class, $crm);
    }
}
