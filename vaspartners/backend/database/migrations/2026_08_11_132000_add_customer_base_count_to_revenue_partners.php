<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('revenue_partners')) {
            return;
        }

        Schema::table('revenue_partners', function (Blueprint $table): void {
            if (! Schema::hasColumn('revenue_partners', 'customer_base_count')) {
                $table->unsignedInteger('customer_base_count')->nullable()->after('spid');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('revenue_partners')) {
            return;
        }

        Schema::table('revenue_partners', function (Blueprint $table): void {
            if (Schema::hasColumn('revenue_partners', 'customer_base_count')) {
                $table->dropColumn('customer_base_count');
            }
        });
    }
};
