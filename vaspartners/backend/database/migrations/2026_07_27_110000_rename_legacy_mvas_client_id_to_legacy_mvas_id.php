<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Domain naming: drop MVAS "client" in favour of Contact/Company language.
 * Source dump table remains `clients`; stored FK is now legacy_mvas_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['companies', 'contacts', 'subscriptions'] as $table) {
            if (Schema::hasColumn($table, 'legacy_mvas_client_id') && ! Schema::hasColumn($table, 'legacy_mvas_id')) {
                DB::statement("ALTER TABLE {$table} RENAME COLUMN legacy_mvas_client_id TO legacy_mvas_id");
            }
        }

        if (Schema::hasTable('contacts')) {
            DB::statement("UPDATE contacts SET sub = REPLACE(sub, 'mvas-client-', 'mvas-contact-') WHERE sub LIKE 'mvas-client-%'");
            DB::statement("UPDATE contacts SET identification_number = REPLACE(identification_number, 'mvas-client-', 'mvas-contact-') WHERE identification_number LIKE 'mvas-client-%'");
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('contacts')) {
            DB::statement("UPDATE contacts SET sub = REPLACE(sub, 'mvas-contact-', 'mvas-client-') WHERE sub LIKE 'mvas-contact-%'");
            DB::statement("UPDATE contacts SET identification_number = REPLACE(identification_number, 'mvas-contact-', 'mvas-client-') WHERE identification_number LIKE 'mvas-contact-%'");
        }

        foreach (['companies', 'contacts', 'subscriptions'] as $table) {
            if (Schema::hasColumn($table, 'legacy_mvas_id') && ! Schema::hasColumn($table, 'legacy_mvas_client_id')) {
                DB::statement("ALTER TABLE {$table} RENAME COLUMN legacy_mvas_id TO legacy_mvas_client_id");
            }
        }
    }
};
