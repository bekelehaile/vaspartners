<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Explicit "sent" row status + import sent_count for Monthly Revenue SMS.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('revenue_imports', 'sent_count')) {
            Schema::table('revenue_imports', function (Blueprint $table): void {
                $table->unsignedInteger('sent_count')->default(0)->after('matched_count');
            });
        }

        DB::table('revenue_import_rows')
            ->where(function ($q): void {
                $q->whereNotNull('sent_at')->orWhereNotNull('bulk_message_id');
            })
            ->where('status', '!=', 'sent')
            ->update(['status' => 'sent']);

        // Refresh sent_count from rows.
        $imports = DB::table('revenue_imports')->pluck('id');
        foreach ($imports as $importId) {
            $sent = DB::table('revenue_import_rows')
                ->where('revenue_import_id', $importId)
                ->where('status', 'sent')
                ->count();
            $matched = DB::table('revenue_import_rows')
                ->where('revenue_import_id', $importId)
                ->where('status', 'matched')
                ->count();
            DB::table('revenue_imports')->where('id', $importId)->update([
                'sent_count' => $sent,
                'matched_count' => $matched,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('revenue_import_rows')
            ->where('status', 'sent')
            ->update(['status' => 'matched']);

        Schema::table('revenue_imports', function (Blueprint $table): void {
            $table->dropColumn('sent_count');
        });
    }
};
