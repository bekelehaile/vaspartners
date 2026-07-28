<?php

use App\Enums\RevenueImportStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Track SMS per import row so ready rows can be sent without waiting for the whole file.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('revenue_import_rows', function (Blueprint $table): void {
            $table->foreignId('bulk_message_id')
                ->nullable()
                ->after('error')
                ->constrained('bulk_messages')
                ->nullOnDelete();
            $table->timestamp('sent_at')->nullable()->after('bulk_message_id');
            $table->index(['revenue_import_id', 'sent_at']);
        });

        // Prior full-import sends: mark ready rows as already sent.
        $imports = DB::table('revenue_imports')
            ->whereNotNull('bulk_message_id')
            ->whereIn('status', [
                RevenueImportStatus::Sending->value,
                RevenueImportStatus::Completed->value,
            ])
            ->get(['id', 'bulk_message_id', 'sent_at']);

        foreach ($imports as $import) {
            DB::table('revenue_import_rows')
                ->where('revenue_import_id', $import->id)
                ->where('status', 'matched')
                ->whereNull('sent_at')
                ->update([
                    'bulk_message_id' => $import->bulk_message_id,
                    'sent_at' => $import->sent_at ?? now(),
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('revenue_import_rows', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('bulk_message_id');
            $table->dropColumn('sent_at');
        });
    }
};
