<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('company_sms_campaigns') && ! Schema::hasTable('bulk_messages')) {
            Schema::rename('company_sms_campaigns', 'bulk_messages');
        }

        if (Schema::hasTable('company_sms_recipients') && ! Schema::hasTable('bulk_message_recipients')) {
            Schema::rename('company_sms_recipients', 'bulk_message_recipients');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('bulk_messages') && ! Schema::hasTable('company_sms_campaigns')) {
            Schema::rename('bulk_messages', 'company_sms_campaigns');
        }

        if (Schema::hasTable('bulk_message_recipients') && ! Schema::hasTable('company_sms_recipients')) {
            Schema::rename('bulk_message_recipients', 'company_sms_recipients');
        }
    }
};
