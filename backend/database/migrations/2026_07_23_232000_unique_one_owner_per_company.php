<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // At most one owner per company (soft-deleted customers excluded).
        DB::statement("
            CREATE UNIQUE INDEX customers_one_owner_per_company
            ON customers (company_id)
            WHERE company_id IS NOT NULL
              AND company_role = 'owner'
              AND deleted_at IS NULL
        ");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS customers_one_owner_per_company');
    }
};
