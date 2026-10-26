<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            if (! Schema::hasColumn('services', 'provisioning_workflow')) {
                $table->string('provisioning_workflow', 64)->nullable()->after('type');
            }
        });

        Schema::create('service_provisionings', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 32)->unique();
            $table->foreignId('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('services')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('workflow_key', 64);
            $table->string('status', 32)->default('draft');
            $table->string('current_step_key', 64)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->nullableMorphs('created_by');
            $table->json('shared_payload')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'current_step_key']);
            $table->index(['company_id', 'status']);
            $table->index(['subscription_id', 'status']);
        });

        Schema::create('service_provisioning_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('service_provisioning_id')->constrained('service_provisionings')->cascadeOnDelete();
            $table->string('step_key', 64);
            $table->string('label', 160);
            $table->unsignedSmallInteger('seq');
            $table->string('parallel_group', 64)->nullable();
            $table->string('from_department', 64);
            $table->string('to_department', 64);
            $table->string('status', 32)->default('pending');
            $table->json('payload')->nullable();
            $table->text('remark')->nullable();
            $table->nullableMorphs('created_by');
            $table->nullableMorphs('completed_by');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['service_provisioning_id', 'seq']);
            $table->index(['status', 'to_department']);
            $table->unique(['service_provisioning_id', 'step_key']);
        });

        Schema::create('service_provisioning_media', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('service_provisioning_step_id')->constrained('service_provisioning_steps')->cascadeOnDelete();
            $table->string('disk', 32)->default('local');
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->string('mime', 128)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->nullableMorphs('uploaded_by');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_provisioning_media');
        Schema::dropIfExists('service_provisioning_steps');
        Schema::dropIfExists('service_provisionings');

        Schema::table('services', function (Blueprint $table): void {
            if (Schema::hasColumn('services', 'provisioning_workflow')) {
                $table->dropColumn('provisioning_workflow');
            }
        });
    }
};
