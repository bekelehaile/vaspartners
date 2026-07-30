<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Collapse contact ban into is_active: banned contacts become inactive, then drop is_banned.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('contacts', 'is_banned')) {
            return;
        }

        DB::table('contacts')
            ->where('is_banned', true)
            ->update(['is_active' => false]);

        Schema::table('contacts', function (Blueprint $table): void {
            $table->dropColumn('is_banned');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('contacts', 'is_banned')) {
            return;
        }

        Schema::table('contacts', function (Blueprint $table): void {
            $table->boolean('is_banned')->default(false)->after('is_active');
        });

        // Cannot restore which inactives were formerly banned.
    }
};
