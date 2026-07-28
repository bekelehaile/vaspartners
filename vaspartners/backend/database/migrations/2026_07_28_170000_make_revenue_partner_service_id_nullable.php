<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Service ID and short code are both optional; at least one must be present (enforced in app).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('revenue_partners')) {
            return;
        }

        Schema::table('revenue_partners', function (Blueprint $table): void {
            $table->string('service_id', 64)->nullable()->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('revenue_partners')) {
            return;
        }

        Schema::table('revenue_partners', function (Blueprint $table): void {
            $table->string('service_id', 64)->nullable(false)->change();
        });
    }
};
