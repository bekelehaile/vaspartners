<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('license_number', 64)->nullable()->after('tin');
        });

        DB::statement("
            UPDATE companies
            SET license_number = 'LEGACY-' || UPPER(tin),
                updated_at = NOW()
            WHERE license_number IS NULL OR license_number = ''
        ");

        DB::statement('ALTER TABLE companies ALTER COLUMN license_number SET NOT NULL');
        Schema::table('companies', function (Blueprint $table) {
            $table->unique('license_number');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->string('company_license_number', 64)->nullable()->after('company_tin');
        });

        DB::statement('
            UPDATE customers c
            SET company_license_number = co.license_number
            FROM companies co
            WHERE c.company_id = co.id
              AND c.company_license_number IS NULL
        ');

        Schema::table('company_change_requests', function (Blueprint $table) {
            $table->foreignId('reviewed_by_customer_id')
                ->nullable()
                ->after('reviewed_by_user_id')
                ->constrained('customers')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('company_change_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by_customer_id');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('company_license_number');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropUnique(['license_number']);
            $table->dropColumn('license_number');
        });
    }
};
