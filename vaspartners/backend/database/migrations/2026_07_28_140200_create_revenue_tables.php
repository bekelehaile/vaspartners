<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Revenue master list + monthly imports mapped to existing catalog services.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('revenue_partners', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            // Finance / billing endpoint ID from Excel (not services.id).
            $table->string('service_id', 64)->nullable()->unique();
            $table->string('short_code', 64)->nullable()->unique();
            $table->foreignId('vas_service_id')->constrained('services')->restrictOnDelete();
            $table->string('partner_name');
            $table->string('phone', 32)->nullable()->index();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true)->index();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('partner_name');
            $table->index('created_by_user_id');
        });

        Schema::create('revenue_imports', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('title');
            $table->string('period', 64)->index();
            $table->foreignId('vas_service_id')->constrained('services')->restrictOnDelete();
            $table->string('source_filename')->nullable();
            $table->foreignId('filament_import_id')->nullable()->constrained('imports')->nullOnDelete();
            $table->string('status', 32)->default('draft')->index();
            $table->text('message_template')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('sent_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('bulk_message_id')->nullable()->constrained('bulk_messages')->nullOnDelete();
            $table->unsignedInteger('total_count')->default(0);
            $table->unsignedInteger('valid_count')->default(0);
            $table->unsignedInteger('matched_count')->default(0);
            $table->unsignedInteger('missing_partner_count')->default(0);
            $table->unsignedInteger('missing_phone_count')->default(0);
            $table->unsignedInteger('invalid_count')->default(0);
            $table->timestamp('imported_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });

        Schema::create('revenue_import_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('revenue_import_id')->constrained('revenue_imports')->cascadeOnDelete();
            $table->foreignId('revenue_partner_id')->nullable()->constrained('revenue_partners')->nullOnDelete();
            // Same catalog service as the parent import (existing services.id).
            $table->foreignId('vas_service_id')->constrained('services')->restrictOnDelete();
            $table->unsignedInteger('row_number')->nullable();
            // Finance billing ID from Excel (not services.id).
            $table->string('service_id', 64)->nullable();
            $table->string('partner_name')->nullable();
            $table->string('short_code', 64)->nullable();
            $table->decimal('amount', 18, 4)->nullable();
            $table->string('amount_raw', 64)->nullable();
            $table->string('status', 32)->default('invalid')->index();
            $table->string('error', 500)->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->index(['revenue_import_id', 'status']);
            $table->index(['revenue_import_id', 'service_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('revenue_import_rows');
        Schema::dropIfExists('revenue_imports');
        Schema::dropIfExists('revenue_partners');
    }
};
