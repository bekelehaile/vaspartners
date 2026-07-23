<?php

use App\Enums\SubscriptionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Keep the oldest alive subscription per customer+service; terminate extras.
        $alive = [
            SubscriptionStatus::Active->value,
            SubscriptionStatus::PendingRenewal->value,
            SubscriptionStatus::Grace->value,
        ];

        $duplicates = DB::table('subscriptions')
            ->select('customer_id', 'service_id')
            ->whereNull('deleted_at')
            ->whereIn('status', $alive)
            ->groupBy('customer_id', 'service_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $pair) {
            $ids = DB::table('subscriptions')
                ->whereNull('deleted_at')
                ->where('customer_id', $pair->customer_id)
                ->where('service_id', $pair->service_id)
                ->whereIn('status', $alive)
                ->orderBy('id')
                ->pluck('id');

            $keep = $ids->shift();
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

            unset($keep);
        }

        DB::statement("
            CREATE UNIQUE INDEX IF NOT EXISTS subscriptions_one_alive_per_customer_service
            ON subscriptions (customer_id, service_id)
            WHERE deleted_at IS NULL
              AND status IN ('active', 'pending_renewal', 'grace')
        ");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS subscriptions_one_alive_per_customer_service');
    }
};
