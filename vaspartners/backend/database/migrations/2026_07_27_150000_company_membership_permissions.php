<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_memberships', function (Blueprint $table) {
            $table->json('permissions')->nullable()->after('is_active');
        });

        // Existing members keep the ability to create service requests by default.
        if (Schema::hasTable('company_memberships')) {
            $default = json_encode(['create_service_requests']);
            DB::table('company_memberships')
                ->where('role', 'member')
                ->whereNull('permissions')
                ->update(['permissions' => $default]);
        }
    }

    public function down(): void
    {
        Schema::table('company_memberships', function (Blueprint $table) {
            $table->dropColumn('permissions');
        });
    }
};
