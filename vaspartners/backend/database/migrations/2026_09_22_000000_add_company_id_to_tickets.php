<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->foreignId('company_id')
                ->nullable()
                ->after('subscription_id')
                ->constrained('companies')
                ->nullOnDelete();
            $table->index(['company_id', 'status'], 'tickets_company_status');
        });

        // Backfill: tickets that already ride a company subscription inherit it.
        \Illuminate\Support\Facades\DB::statement('
            UPDATE tickets t
            SET company_id = s.company_id
            FROM subscriptions s
            WHERE t.subscription_id = s.id
              AND s.company_id IS NOT NULL
              AND t.company_id IS NULL
        ');
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropIndex('tickets_company_status');
            $table->dropColumn('company_id');
        });
    }
};