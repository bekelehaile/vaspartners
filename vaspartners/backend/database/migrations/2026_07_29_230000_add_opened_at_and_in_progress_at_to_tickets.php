<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Each ticket status has its own event timestamp:
 * open → opened_at, in_progress → in_progress_at,
 * completed → completed_at, rejected → rejected_at, closed → closed_at.
 * assigned_at remains the handler-assignment clock (separate from status entry).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table): void {
            $table->timestamp('opened_at')->nullable()->after('description');
            $table->timestamp('in_progress_at')->nullable()->after('assigned_at');
        });

        // Backfill: first open ≈ submit time; in-progress ≈ prior assigned_at when present.
        DB::table('tickets')->whereNull('opened_at')->update([
            'opened_at' => DB::raw('created_at'),
        ]);

        DB::table('tickets')
            ->whereNull('in_progress_at')
            ->whereNotNull('assigned_at')
            ->update([
                'in_progress_at' => DB::raw('assigned_at'),
            ]);

        DB::table('tickets')
            ->whereNull('in_progress_at')
            ->where('status', 'in_progress')
            ->update([
                'in_progress_at' => DB::raw('coalesce(assigned_at, created_at)'),
            ]);
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table): void {
            $table->dropColumn(['opened_at', 'in_progress_at']);
        });
    }
};
