<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bulk_message_recipients', function (Blueprint $table): void {
            if (! Schema::hasColumn('bulk_message_recipients', 'variables')) {
                $table->json('variables')->nullable()->after('company_tin');
            }
        });
    }

    public function down(): void
    {
        Schema::table('bulk_message_recipients', function (Blueprint $table): void {
            if (Schema::hasColumn('bulk_message_recipients', 'variables')) {
                $table->dropColumn('variables');
            }
        });
    }
};
