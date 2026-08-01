<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contacts (legacy table name customers → contacts): indexes for list tabs/filters/sort.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX IF NOT EXISTS contacts_current_company_idx ON contacts (current_company_id) WHERE deleted_at IS NULL');
            DB::statement('CREATE INDEX IF NOT EXISTS contacts_created_at_alive_idx ON contacts (created_at DESC) WHERE deleted_at IS NULL');
            DB::statement('CREATE INDEX IF NOT EXISTS contacts_active_idx ON contacts (is_active) WHERE deleted_at IS NULL');
            DB::statement('CREATE INDEX IF NOT EXISTS contacts_identity_idx ON contacts (identity_verified_via, fayda_verified) WHERE deleted_at IS NULL');

            return;
        }

        Schema::table('contacts', function (Blueprint $table): void {
            $table->index(['current_company_id'], 'contacts_current_company_idx');
            $table->index(['created_at'], 'contacts_created_at_idx');
            $table->index(['is_active'], 'contacts_active_idx');
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS contacts_current_company_idx');
            DB::statement('DROP INDEX IF EXISTS contacts_created_at_alive_idx');
            DB::statement('DROP INDEX IF EXISTS contacts_active_idx');
            DB::statement('DROP INDEX IF EXISTS contacts_identity_idx');

            return;
        }

        Schema::table('contacts', function (Blueprint $table): void {
            $table->dropIndex('contacts_current_company_idx');
            $table->dropIndex('contacts_created_at_idx');
            $table->dropIndex('contacts_active_idx');
        });
    }
};
