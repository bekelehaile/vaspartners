<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->unsignedBigInteger('legacy_mvas_client_id')
                ->nullable()
                ->unique();
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->unsignedBigInteger('legacy_mvas_ticket_id')
                ->nullable()
                ->unique();
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropUnique(['legacy_mvas_ticket_id']);
            $table->dropColumn('legacy_mvas_ticket_id');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique(['legacy_mvas_client_id']);
            $table->dropColumn('legacy_mvas_client_id');
        });
    }
};
