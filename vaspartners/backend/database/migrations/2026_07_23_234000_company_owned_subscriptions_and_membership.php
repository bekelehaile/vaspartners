<?php

use App\Enums\SubscriptionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('company_membership_active')
                ->default(true)
                ->after('company_role');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->foreignId('company_id')
                ->nullable()
                ->after('customer_id')
                ->constrained('companies')
                ->restrictOnDelete();
            $table->index(['company_id', 'service_id', 'status'], 'subscriptions_company_service_status');
        });

        // Backfill: subscriptions belong to the customer's company when linked.
        DB::statement('
            UPDATE subscriptions s
            SET company_id = c.company_id
            FROM customers c
            WHERE s.customer_id = c.id
              AND c.company_id IS NOT NULL
              AND s.company_id IS NULL
        ');

        // Prefer company-level uniqueness for alive subscriptions.
        DB::statement('DROP INDEX IF EXISTS subscriptions_one_alive_per_customer_service');

        $alive = [
            SubscriptionStatus::Active->value,
            SubscriptionStatus::PendingRenewal->value,
            SubscriptionStatus::Grace->value,
        ];

        // Collapse duplicate alive rows per company+service (keep oldest).
        $duplicates = DB::table('subscriptions')
            ->select('company_id', 'service_id')
            ->whereNull('deleted_at')
            ->whereNotNull('company_id')
            ->whereIn('status', $alive)
            ->groupBy('company_id', 'service_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $pair) {
            $ids = DB::table('subscriptions')
                ->whereNull('deleted_at')
                ->where('company_id', $pair->company_id)
                ->where('service_id', $pair->service_id)
                ->whereIn('status', $alive)
                ->orderBy('id')
                ->pluck('id');

            $ids->shift();
            if ($ids->isEmpty()) {
                continue;
            }

            DB::table('subscriptions')
                ->whereIn('id', $ids->all())
                ->update([
                    'status' => SubscriptionStatus::Terminated->value,
                    'terminated_at' => now(),
                    'updated_at' => now(),
                ]);
        }

        DB::statement("
            CREATE UNIQUE INDEX IF NOT EXISTS subscriptions_one_alive_per_company_service
            ON subscriptions (company_id, service_id)
            WHERE deleted_at IS NULL
              AND company_id IS NOT NULL
              AND status IN ('active', 'pending_renewal', 'grace')
        ");

        // Hard-delete of a company must fail while members or subscriptions exist.
        DB::statement('ALTER TABLE customers DROP CONSTRAINT IF EXISTS customers_company_id_foreign');
        DB::statement('
            ALTER TABLE customers
            ADD CONSTRAINT customers_company_id_foreign
            FOREIGN KEY (company_id) REFERENCES companies(id)
            ON DELETE RESTRICT
        ');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE customers DROP CONSTRAINT IF EXISTS customers_company_id_foreign');
        DB::statement('
            ALTER TABLE customers
            ADD CONSTRAINT customers_company_id_foreign
            FOREIGN KEY (company_id) REFERENCES companies(id)
            ON DELETE SET NULL
        ');

        DB::statement('DROP INDEX IF EXISTS subscriptions_one_alive_per_company_service');

        DB::statement("
            CREATE UNIQUE INDEX IF NOT EXISTS subscriptions_one_alive_per_customer_service
            ON subscriptions (customer_id, service_id)
            WHERE deleted_at IS NULL
              AND status IN ('active', 'pending_renewal', 'grace')
        ");

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex('subscriptions_company_service_status');
            $table->dropConstrainedForeignId('company_id');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('company_membership_active');
        });
    }
};
