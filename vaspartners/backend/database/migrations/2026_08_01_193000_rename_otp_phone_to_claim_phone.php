<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rename companies.otp_phone → claim_phone (portal claim / partner contact phone).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('companies', 'otp_phone') && ! Schema::hasColumn('companies', 'claim_phone')) {
            DB::statement('ALTER TABLE companies RENAME COLUMN otp_phone TO claim_phone');
        }

        Schema::table('companies', function (Blueprint $table) {
            if ($this->indexExists('companies_otp_phone_index')) {
                $table->dropIndex('companies_otp_phone_index');
            }
        });

        if (Schema::hasColumn('companies', 'claim_phone') && ! $this->indexExists('companies_claim_phone_index')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->index('claim_phone', 'companies_claim_phone_index');
            });
        }
    }

    public function down(): void
    {
        if ($this->indexExists('companies_claim_phone_index')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->dropIndex('companies_claim_phone_index');
            });
        }

        if (Schema::hasColumn('companies', 'claim_phone') && ! Schema::hasColumn('companies', 'otp_phone')) {
            DB::statement('ALTER TABLE companies RENAME COLUMN claim_phone TO otp_phone');
        }

        if (Schema::hasColumn('companies', 'otp_phone') && ! $this->indexExists('companies_otp_phone_index')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->index('otp_phone', 'companies_otp_phone_index');
            });
        }
    }

    protected function indexExists(string $name): bool
    {
        $rows = DB::select(
            'SELECT 1 FROM pg_indexes WHERE schemaname = current_schema() AND indexname = ? LIMIT 1',
            [$name],
        );

        return $rows !== [];
    }
};
