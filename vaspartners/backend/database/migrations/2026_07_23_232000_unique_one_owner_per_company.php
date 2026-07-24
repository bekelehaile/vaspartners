<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // At most one owner per company (soft-deleted contacts excluded).
        DB::statement("
            CREATE UNIQUE INDEX contacts_one_owner_per_company
            ON contacts (company_id)
            WHERE company_id IS NOT NULL
              AND company_role = 'owner'
              AND deleted_at IS NULL
        ");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS contacts_one_owner_per_company');
    }
};
