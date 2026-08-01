<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Speed default ticket list sort (created_at desc) for non-deleted rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX IF NOT EXISTS tickets_created_at_alive_idx ON tickets (created_at DESC) WHERE deleted_at IS NULL');

            return;
        }

        Schema::table('tickets', function (Blueprint $table): void {
            $table->index(['created_at'], 'tickets_created_at_idx');
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS tickets_created_at_alive_idx');

            return;
        }

        Schema::table('tickets', function (Blueprint $table): void {
            $table->dropIndex('tickets_created_at_idx');
        });
    }
};
