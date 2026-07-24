<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('role', 16); // owner|member
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['contact_id', 'company_id']);
            $table->index(['company_id', 'role']);
            $table->index(['contact_id', 'is_active']);
        });

        // One owner per company.
        DB::statement("
            CREATE UNIQUE INDEX company_memberships_one_owner_per_company
            ON company_memberships (company_id)
            WHERE role = 'owner'
        ");

        Schema::table('contacts', function (Blueprint $table) {
            $table->foreignId('current_company_id')
                ->nullable()
                ->after('profile_completed_at')
                ->constrained('companies')
                ->nullOnDelete();
        });

        // Backfill memberships from legacy single-company columns.
        DB::statement('
            INSERT INTO company_memberships (contact_id, company_id, role, is_active, created_at, updated_at)
            SELECT
                c.id,
                c.company_id,
                COALESCE(NULLIF(c.company_role, \'\'), \'member\'),
                COALESCE(c.company_membership_active, true),
                NOW(),
                NOW()
            FROM contacts c
            WHERE c.company_id IS NOT NULL
              AND c.deleted_at IS NULL
            ON CONFLICT DO NOTHING
        ');

        DB::statement('
            UPDATE contacts
            SET current_company_id = company_id
            WHERE company_id IS NOT NULL
        ');

        DB::statement('DROP INDEX IF EXISTS contacts_one_owner_per_company');

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
            $table->dropColumn(['company_role', 'company_membership_active']);
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->constrained('companies')->restrictOnDelete();
            $table->string('company_role', 16)->nullable();
            $table->boolean('company_membership_active')->default(true);
        });

        DB::statement('
            UPDATE contacts c
            SET
                company_id = m.company_id,
                company_role = m.role,
                company_membership_active = m.is_active
            FROM company_memberships m
            WHERE m.contact_id = c.id
              AND m.company_id = c.current_company_id
        ');

        DB::statement("
            CREATE UNIQUE INDEX contacts_one_owner_per_company
            ON contacts (company_id)
            WHERE company_id IS NOT NULL
              AND company_role = 'owner'
              AND deleted_at IS NULL
        ");

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_company_id');
        });

        DB::statement('DROP INDEX IF EXISTS company_memberships_one_owner_per_company');
        Schema::dropIfExists('company_memberships');
    }
};
