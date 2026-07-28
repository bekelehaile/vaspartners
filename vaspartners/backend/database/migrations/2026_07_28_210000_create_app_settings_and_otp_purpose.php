<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        DB::table('app_settings')->insert([
            [
                'key' => 'auth_mode',
                'value' => 'both',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        Schema::table('otps', function (Blueprint $table): void {
            $table->string('purpose', 64)->default('admin_password_reset')->after('phone_number')->index();
        });
    }

    public function down(): void
    {
        Schema::table('otps', function (Blueprint $table): void {
            $table->dropColumn('purpose');
        });
        Schema::dropIfExists('app_settings');
    }
};
