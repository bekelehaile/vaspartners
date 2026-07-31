<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Subscription end-state is "deactive" (system), not request "closed".
 * Partner still consents via a Termination service request; when that request
 * is completed/closed, the subscription becomes deactive.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('subscriptions')
            ->where('status', 'terminated')
            ->update([
                'status' => 'deactive',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('subscriptions')
            ->where('status', 'deactive')
            ->update([
                'status' => 'terminated',
                'updated_at' => now(),
            ]);
    }
};
