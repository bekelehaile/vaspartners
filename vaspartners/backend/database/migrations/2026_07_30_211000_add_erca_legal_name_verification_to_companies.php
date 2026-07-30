<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ERCA / eTrade TIN verification: store registry legal name, match status,
 * and schedule metadata so we can re-check slowly without flooding the API.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->string('legal_name')->nullable()->after('name');
            $table->boolean('erca_tin_verified')->default(false)->after('tin_validated_at');
            $table->timestamp('erca_verified_at')->nullable()->after('erca_tin_verified');
            // unchecked|matched|mismatch_pending|accepted_legal|kept_both|not_found|failed
            $table->string('erca_name_status', 32)->default('unchecked')->after('erca_verified_at');
            $table->timestamp('erca_last_checked_at')->nullable()->after('erca_name_status');
            $table->timestamp('erca_next_check_at')->nullable()->after('erca_last_checked_at');
            $table->text('erca_last_error')->nullable()->after('erca_next_check_at');

            $table->index(['erca_next_check_at', 'erca_name_status'], 'companies_erca_schedule_idx');
            $table->index(['erca_tin_verified', 'erca_name_status'], 'companies_erca_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropIndex('companies_erca_schedule_idx');
            $table->dropIndex('companies_erca_status_idx');
            $table->dropColumn([
                'legal_name',
                'erca_tin_verified',
                'erca_verified_at',
                'erca_name_status',
                'erca_last_checked_at',
                'erca_next_check_at',
                'erca_last_error',
            ]);
        });
    }
};
