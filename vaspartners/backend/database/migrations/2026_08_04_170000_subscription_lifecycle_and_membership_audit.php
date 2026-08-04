<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->string('operational_status', 32)
                ->default('unknown')
                ->after('status');
            $table->timestamp('operational_status_updated_at')->nullable()->after('operational_status');
            $table->index('operational_status');
        });

        Schema::create('subscription_provisioning_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->string('event', 64);
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32)->nullable();
            $table->nullableMorphs('actor');
            $table->foreignId('ticket_id')->nullable()->constrained()->nullOnDelete();
            $table->text('note')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['subscription_id', 'created_at']);
            $table->index('event');
        });

        Schema::create('company_membership_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('membership_id')->nullable()->constrained('company_memberships')->nullOnDelete();
            $table->foreignId('member_contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->string('action', 64);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actor_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'created_at']);
            $table->index(['member_contact_id', 'created_at']);
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_membership_audit_logs');
        Schema::dropIfExists('subscription_provisioning_logs');

        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropIndex(['operational_status']);
            $table->dropColumn(['operational_status', 'operational_status_updated_at']);
        });
    }
};
