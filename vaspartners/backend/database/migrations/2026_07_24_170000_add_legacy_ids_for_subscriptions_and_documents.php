<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_documents', function (Blueprint $table) {
            $table->unsignedBigInteger('legacy_mvas_file_id')
                ->nullable()
                ->unique();
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->unsignedBigInteger('legacy_mvas_client_id')->nullable()->index();
            $table->unsignedBigInteger('legacy_mvas_service_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['legacy_mvas_client_id', 'legacy_mvas_service_id']);
        });

        Schema::table('ticket_documents', function (Blueprint $table) {
            $table->dropUnique(['legacy_mvas_file_id']);
            $table->dropColumn('legacy_mvas_file_id');
        });
    }
};
