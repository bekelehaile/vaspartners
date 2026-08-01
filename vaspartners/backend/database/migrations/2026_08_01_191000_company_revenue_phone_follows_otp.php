<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * For now revenue_phone follows otp_phone (same value).
 * A later approved request can set revenue_phone to a different number.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            UPDATE companies
            SET revenue_phone = COALESCE(NULLIF(TRIM(otp_phone), ''), NULLIF(TRIM(phone), ''), revenue_phone)
            WHERE deleted_at IS NULL
        ");
    }

    public function down(): void
    {
        // Irreversible data sync — no schema change.
    }
};
