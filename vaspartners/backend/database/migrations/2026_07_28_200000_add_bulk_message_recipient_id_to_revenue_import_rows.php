<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Link each sent revenue row to its SMS recipient for status + retries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('revenue_import_rows', function (Blueprint $table): void {
            $table->foreignId('bulk_message_recipient_id')
                ->nullable()
                ->after('bulk_message_id')
                ->constrained('bulk_message_recipients')
                ->nullOnDelete();
        });

        $rows = DB::table('revenue_import_rows')
            ->whereNotNull('bulk_message_id')
            ->whereNull('bulk_message_recipient_id')
            ->get(['id', 'bulk_message_id', 'service_id', 'short_code', 'revenue_partner_id']);

        foreach ($rows as $row) {
            $query = DB::table('bulk_message_recipients')
                ->where('campaign_id', $row->bulk_message_id);

            $recipientId = null;
            if (filled($row->service_id)) {
                $recipientId = (clone $query)
                    ->where('variables->service_id', (string) $row->service_id)
                    ->value('id');
            }

            if (! $recipientId && filled($row->short_code)) {
                $recipientId = (clone $query)
                    ->where('variables->service_id', (string) $row->short_code)
                    ->value('id');
            }

            if (! $recipientId && $row->revenue_partner_id) {
                $phone = DB::table('revenue_partners')->where('id', $row->revenue_partner_id)->value('phone');
                if (filled($phone)) {
                    $recipientId = (clone $query)
                        ->where('phone_normalized', (string) $phone)
                        ->value('id');
                }
            }

            if ($recipientId) {
                DB::table('revenue_import_rows')->where('id', $row->id)->update([
                    'bulk_message_recipient_id' => $recipientId,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('revenue_import_rows', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('bulk_message_recipient_id');
        });
    }
};
