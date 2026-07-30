<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TIN OK is ERCA-only — drop admin attribution columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            if (Schema::hasColumn('companies', 'tin_validated_by_user_id')) {
                $table->dropConstrainedForeignId('tin_validated_by_user_id');
            }
            if (Schema::hasColumn('companies', 'tin_validated_at')) {
                $table->dropColumn('tin_validated_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            if (! Schema::hasColumn('companies', 'tin_validated_by_user_id')) {
                $table->foreignId('tin_validated_by_user_id')
                    ->nullable()
                    ->after('tin_validated')
                    ->constrained('users')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('companies', 'tin_validated_at')) {
                $table->timestamp('tin_validated_at')->nullable()->after('tin_validated');
            }
        });
    }
};
