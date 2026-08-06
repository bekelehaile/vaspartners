<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contract follow-up: renewal is a full date (day/month/year), not year-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            if (! Schema::hasColumn('subscriptions', 'renewal_date')) {
                $table->date('renewal_date')->nullable()->after('contract_signed_at');
            }
        });

        if (Schema::hasColumn('subscriptions', 'renewal_year')) {
            DB::statement(<<<'SQL'
                UPDATE subscriptions
                SET renewal_date = make_date(renewal_year::int, 1, 1)
                WHERE renewal_year IS NOT NULL
                  AND renewal_date IS NULL
                  AND renewal_year BETWEEN 1900 AND 2100
            SQL);

            Schema::table('subscriptions', function (Blueprint $table): void {
                $table->dropColumn('renewal_year');
            });
        }
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            if (! Schema::hasColumn('subscriptions', 'renewal_year')) {
                $table->unsignedSmallInteger('renewal_year')->nullable()->after('contract_signed_at');
            }
        });

        if (Schema::hasColumn('subscriptions', 'renewal_date')) {
            DB::statement(<<<'SQL'
                UPDATE subscriptions
                SET renewal_year = EXTRACT(YEAR FROM renewal_date)::int
                WHERE renewal_date IS NOT NULL
                  AND renewal_year IS NULL
            SQL);

            Schema::table('subscriptions', function (Blueprint $table): void {
                $table->dropColumn('renewal_date');
            });
        }
    }
};
