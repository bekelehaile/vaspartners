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
            if (! Schema::hasColumn('revenue_partners', 'product_id')) {
                $table->string('product_id', 64)->nullable()->after('service_id')->index();
            }
            if (! Schema::hasColumn('revenue_partners', 'spid')) {
                $table->string('spid', 64)->nullable()->after('product_id')->index();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('revenue_partners')) {
            return;
        }

        Schema::table('revenue_partners', function (Blueprint $table): void {
            if (Schema::hasColumn('revenue_partners', 'spid')) {
                $table->dropColumn('spid');
            }
            if (Schema::hasColumn('revenue_partners', 'product_id')) {
                $table->dropColumn('product_id');
            }
        });
    }
};
