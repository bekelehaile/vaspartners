<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Initial renewal years (1, 2, …) plus flag for automatic subsequent renewals.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            if (! Schema::hasColumn('subscriptions', 'renewal_years')) {
                $table->unsignedTinyInteger('renewal_years')->nullable()->after('contract_signed_at');
            }
            if (! Schema::hasColumn('subscriptions', 'automatic_renewal')) {
                $table->boolean('automatic_renewal')->default(false)->after('renewal_date');
            }
        });

        // Backfill years from signing → renewal date when possible.
        if (Schema::hasColumn('subscriptions', 'renewal_years')) {
            DB::statement(<<<'SQL'
                UPDATE subscriptions
                SET renewal_years = GREATEST(
                    1,
                    LEAST(
                        20,
                        EXTRACT(YEAR FROM age(renewal_date, contract_signed_at))::int
                    )
                )
                WHERE renewal_years IS NULL
                  AND contract_signed_at IS NOT NULL
                  AND renewal_date IS NOT NULL
                  AND renewal_date >= contract_signed_at
            SQL);
        }
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $cols = array_values(array_filter([
                Schema::hasColumn('subscriptions', 'renewal_years') ? 'renewal_years' : null,
                Schema::hasColumn('subscriptions', 'automatic_renewal') ? 'automatic_renewal' : null,
            ]));

            if ($cols !== []) {
                $table->dropColumn($cols);
            }
        });
    }
};
