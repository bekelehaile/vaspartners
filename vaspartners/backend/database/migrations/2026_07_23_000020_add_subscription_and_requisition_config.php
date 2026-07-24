<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requisitions', function (Blueprint $table) {
            $table->string('code', 64)->nullable()->unique()->after('slug');
            $table->unsignedInteger('sort_order')->default(0)->after('is_active');
            $table->boolean('creates_subscription')->default(false)->after('sort_order');
            $table->boolean('requires_active_subscription')->default(false)->after('creates_subscription');
            $table->boolean('renews_subscription')->default(false)->after('requires_active_subscription');
            $table->boolean('terminates_subscription')->default(false)->after('renews_subscription');
            $table->boolean('is_system')->default(false)->after('terminates_subscription');
        });

        Schema::table('services', function (Blueprint $table) {
            $table->boolean('is_subscription_based')->default(true)->after('is_active');
            $table->string('renewal_interval', 32)->nullable()->after('is_subscription_based'); // yearly|bi_yearly
            $table->unsignedInteger('renewal_lead_days')->default(30)->after('renewal_interval');
            $table->foreignId('renewal_requisition_id')->nullable()->after('renewal_lead_days')
                ->constrained('requisitions')->nullOnDelete();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->restrictOnDelete();
            $table->string('status', 32)->default('active'); // active|pending_renewal|grace|expired|terminated
            $table->string('renewal_interval', 32); // yearly|bi_yearly (snapshot)
            $table->timestamp('started_at');
            $table->timestamp('current_period_start');
            $table->timestamp('current_period_end');
            $table->timestamp('next_renewal_due_at')->nullable();
            $table->foreignId('activated_by_ticket_id')->nullable()->constrained('tickets')->nullOnDelete();
            $table->foreignId('terminated_by_ticket_id')->nullable()->constrained('tickets')->nullOnDelete();
            $table->timestamp('terminated_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['customer_id', 'service_id', 'status'], 'subscriptions_customer_service_status');
            $table->index(['status', 'current_period_end'], 'subscriptions_renewal_due');
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->foreignId('subscription_id')->nullable()->after('requisition_id')
                ->constrained('subscriptions')->nullOnDelete();
            $table->foreignId('parent_ticket_id')->nullable()->after('subscription_id')
                ->constrained('tickets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_ticket_id');
            $table->dropConstrainedForeignId('subscription_id');
        });

        Schema::dropIfExists('subscriptions');

        Schema::table('services', function (Blueprint $table) {
            $table->dropConstrainedForeignId('renewal_requisition_id');
            $table->dropColumn(['is_subscription_based', 'renewal_interval', 'renewal_lead_days']);
        });

        Schema::table('requisitions', function (Blueprint $table) {
            $table->dropColumn([
                'code',
                'sort_order',
                'creates_subscription',
                'requires_active_subscription',
                'renews_subscription',
                'terminates_subscription',
                'is_system',
            ]);
        });
    }
};
