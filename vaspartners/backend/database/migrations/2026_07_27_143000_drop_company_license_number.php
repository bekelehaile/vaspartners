<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Company identity is TIN-only. Drop license_number from companies and
 * denormalized company_license_number from contacts.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('companies', 'license_number')) {
            // PostgreSQL: drop unique constraint without aborting the transaction.
            DB::statement('ALTER TABLE companies DROP CONSTRAINT IF EXISTS companies_license_number_unique');

            // Also drop any leftover unique index with the same name.
            DB::statement('DROP INDEX IF EXISTS companies_license_number_unique');

            Schema::table('companies', function (Blueprint $table) {
                $table->dropColumn('license_number');
            });
        }

        if (Schema::hasColumn('contacts', 'company_license_number')) {
            Schema::table('contacts', function (Blueprint $table) {
                $table->dropColumn('company_license_number');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('companies', 'license_number')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->string('license_number', 64)->nullable()->after('tin');
            });
        }

        if (! Schema::hasColumn('contacts', 'company_license_number')) {
            Schema::table('contacts', function (Blueprint $table) {
                $table->string('company_license_number', 64)->nullable()->after('company_tin');
            });
        }
    }
};
