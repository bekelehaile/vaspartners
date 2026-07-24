<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_change_requests', function (Blueprint $table) {
            $table->foreignId('target_contact_id')
                ->nullable()
                ->after('company_id')
                ->constrained('contacts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('company_change_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('target_contact_id');
        });
    }
};
