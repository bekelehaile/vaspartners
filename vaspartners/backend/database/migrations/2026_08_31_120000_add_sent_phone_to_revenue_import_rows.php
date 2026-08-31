<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot the phone used when revenue SMS was sent (immutable per row).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('revenue_import_rows', function (Blueprint $table): void {
            $table->string('sent_phone', 32)->nullable()->after('sent_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('revenue_import_rows', function (Blueprint $table): void {
            $table->dropIndex(['sent_phone']);
            $table->dropColumn('sent_phone');
        });
    }
};
