<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('company_name')->nullable()->after('name');
            $table->string('company_tin', 64)->nullable()->after('company_name');
            $table->string('company_phone', 32)->nullable()->after('company_tin');
            $table->string('company_email')->nullable()->after('company_phone');
            $table->text('company_address')->nullable()->after('company_email');
            $table->timestamp('profile_completed_at')->nullable()->after('is_banned');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn([
                'company_name',
                'company_tin',
                'company_phone',
                'company_email',
                'company_address',
                'profile_completed_at',
            ]);
        });
    }
};
