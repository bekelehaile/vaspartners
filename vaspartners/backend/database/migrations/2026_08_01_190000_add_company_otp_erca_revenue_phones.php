<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Split company contact into three clear phones:
 * - otp_phone: partner portal OTP / auto-claim (customer-facing)
 * - erca_phone: Ministry of Revenues / ERCA registry (synced on TIN verify)
 * - revenue_phone: revenue collection & bulk SMS destination
 *
 * Legacy companies.phone stays as a mirror of otp_phone for older queries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('otp_phone', 32)->nullable()->after('phone');
            $table->string('erca_phone', 32)->nullable()->after('otp_phone');
            $table->string('revenue_phone', 32)->nullable()->after('erca_phone');
            $table->index('otp_phone', 'companies_otp_phone_index');
            $table->index('erca_phone', 'companies_erca_phone_index');
            $table->index('revenue_phone', 'companies_revenue_phone_index');
        });

        // Backfill: existing phone was the operational/owner contact — use for OTP + revenue.
        DB::table('companies')->orderBy('id')->chunkById(200, function ($rows): void {
            foreach ($rows as $row) {
                $phone = $row->phone !== null && trim((string) $row->phone) !== ''
                    ? trim((string) $row->phone)
                    : null;

                DB::table('companies')->where('id', $row->id)->update([
                    'otp_phone' => $phone,
                    'revenue_phone' => $phone,
                    // erca_phone filled by vas:sync-erca-phones after verify
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropIndex('companies_otp_phone_index');
            $table->dropIndex('companies_erca_phone_index');
            $table->dropIndex('companies_revenue_phone_index');
            $table->dropColumn(['otp_phone', 'erca_phone', 'revenue_phone']);
        });
    }
};
