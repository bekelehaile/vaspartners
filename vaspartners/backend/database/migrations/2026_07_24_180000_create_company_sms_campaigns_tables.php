<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_sms_campaigns', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('title');
            $table->text('message');
            $table->string('source_filename')->nullable();
            $table->string('source_path')->nullable();
            $table->string('status', 32)->default('draft')->index();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('total_count')->default(0);
            $table->unsignedInteger('matched_count')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('company_sms_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('company_sms_campaigns')->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->string('phone_raw', 64)->nullable();
            $table->string('phone_normalized', 16)->nullable()->index();
            $table->string('company_name')->nullable();
            $table->string('company_tin', 64)->nullable();
            $table->unsignedInteger('row_number')->nullable();
            $table->string('status', 32)->default('pending')->index();
            $table->string('error', 500)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['campaign_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_sms_recipients');
        Schema::dropIfExists('company_sms_campaigns');
    }
};
