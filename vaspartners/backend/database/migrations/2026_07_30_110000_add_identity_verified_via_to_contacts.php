<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('identity_verified_via', 16)
                ->nullable()
                ->after('fayda_verified')
                ->comment('fayda|crm — sticky personal KYC source');
            $table->timestamp('identity_verified_at')
                ->nullable()
                ->after('identity_verified_via');
            $table->json('crm_identity_snapshot')
                ->nullable()
                ->after('identity_verified_at');
        });

        // Backfill from existing Fayda-verified contacts.
        DB::table('contacts')
            ->where('fayda_verified', true)
            ->whereNull('identity_verified_via')
            ->update([
                'identity_verified_via' => 'fayda',
                'identity_verified_at' => now(),
            ]);
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn([
                'identity_verified_via',
                'identity_verified_at',
                'crm_identity_snapshot',
            ]);
        });
    }
};
