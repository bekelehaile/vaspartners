<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 64)->nullable()->unique()->after('name');
        });

        // Prefer phone as username; fall back to email local-part.
        DB::table('users')->orderBy('id')->each(function ($user) {
            $username = null;
            if (filled($user->phone)) {
                $digits = preg_replace('/\D/', '', (string) $user->phone) ?? '';
                $username = substr($digits, -9) ?: null;
            }
            if (! $username && filled($user->email) && str_contains((string) $user->email, '@')) {
                $username = strtolower(strstr((string) $user->email, '@', true) ?: '');
            }
            if (! $username) {
                $username = 'user'.$user->id;
            }

            // Ensure uniqueness if collisions occur.
            $base = $username;
            $i = 1;
            while (DB::table('users')->where('username', $username)->where('id', '!=', $user->id)->exists()) {
                $username = $base.$i;
                $i++;
            }

            DB::table('users')->where('id', $user->id)->update(['username' => $username]);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('username');
        });
    }
};
