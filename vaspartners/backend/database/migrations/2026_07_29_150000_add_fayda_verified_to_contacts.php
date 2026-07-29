<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Persistent identity flag: verified via Fayda (National ID) at least once.
 * Survives later phone-OTP sign-ins so status can be checked at any time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table): void {
            $table->boolean('fayda_verified')->default(false)->after('is_banned');
        });

        // Backfill: real Fayda subjects (not invite / MVAS / phone-OTP placeholders).
        DB::table('contacts')
            ->whereNotNull('sub')
            ->where('sub', 'not like', 'invite-%')
            ->where('sub', 'not like', 'mvas-contact-%')
            ->where('sub', 'not like', 'otp-%')
            ->update(['fayda_verified' => true]);
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table): void {
            $table->dropColumn('fayda_verified');
        });
    }
};
