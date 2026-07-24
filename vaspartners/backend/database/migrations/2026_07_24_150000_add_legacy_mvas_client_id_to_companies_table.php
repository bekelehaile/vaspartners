<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->unsignedBigInteger('legacy_mvas_client_id')
                ->nullable()
                ->unique()
                ->after('created_by_customer_id');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropUnique(['legacy_mvas_client_id']);
            $table->dropColumn('legacy_mvas_client_id');
        });
    }
};
