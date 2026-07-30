<?php

use App\Enums\CompanyApprovalStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TIN-approved companies were still blocked on approval_status=pending.
 * Services require both gates; treat TIN confirmation as company approval too.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('companies')
            ->where('tin_validated', true)
            ->where('approval_status', '!=', CompanyApprovalStatus::Approved->value)
            ->update([
                'approval_status' => CompanyApprovalStatus::Approved->value,
                'approved_at' => DB::raw('COALESCE(approved_at, tin_validated_at, NOW())'),
                'is_active' => true,
                'approval_note' => DB::raw(
                    "CASE WHEN approval_note IS NULL OR BTRIM(approval_note) = '' "
                    ."THEN 'Approved with TIN validation (backfill).' "
                    .'ELSE approval_note END'
                ),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Irreversible: cannot restore which pending companies were only TIN-approved.
    }
};
