<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ensure CRM-applied contacts have identity_verified_via = crm.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('contacts')
            ->whereNull('identity_verified_via')
            ->whereNotNull('crm_identity_snapshot')
            ->update([
                'identity_verified_via' => 'crm',
                'identity_verified_at' => DB::raw('COALESCE(identity_verified_at, NOW())'),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Non-destructive backfill — no reverse.
    }
};
