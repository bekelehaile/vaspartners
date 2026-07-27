<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Owner-review workflow only (license number removed — companies use TIN alone).
        Schema::table('company_change_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('company_change_requests', 'reviewed_by_contact_id')) {
                $table->foreignId('reviewed_by_contact_id')
                    ->nullable()
                    ->after('reviewed_by_user_id')
                    ->constrained('contacts')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('company_change_requests', 'reviewed_by_contact_id')) {
            Schema::table('company_change_requests', function (Blueprint $table) {
                $table->dropConstrainedForeignId('reviewed_by_contact_id');
            });
        }
    }
};
