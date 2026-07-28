<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ensure revenue partners are owned by the importing account manager.
 * Safe for staging DBs created before created_by_user_id existed on this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('revenue_partners')) {
            return;
        }

        if (! Schema::hasColumn('revenue_partners', 'created_by_user_id')) {
            Schema::table('revenue_partners', function (Blueprint $table): void {
                $table->foreignId('created_by_user_id')
                    ->nullable()
                    ->after('company_id')
                    ->constrained('users')
                    ->nullOnDelete();
                $table->index('created_by_user_id');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('revenue_partners') || ! Schema::hasColumn('revenue_partners', 'created_by_user_id')) {
            return;
        }

        Schema::table('revenue_partners', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('created_by_user_id');
        });
    }
};
