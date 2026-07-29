<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->foreignId('tin_validated_by_user_id')
                ->nullable()
                ->after('tin_validated')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('tin_validated_at')
                ->nullable()
                ->after('tin_validated_by_user_id');
        });

        Schema::create('company_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('action', 64);
            $table->string('approval_status', 32)->nullable();
            $table->boolean('is_active')->nullable();
            $table->boolean('tin_validated')->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actor_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->text('note')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_status_histories');

        Schema::table('companies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tin_validated_by_user_id');
            $table->dropColumn('tin_validated_at');
        });
    }
};
