<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contract expiration follow-up: signing date, renewal year, and (for premium)
 * VAS license expiry — required before a subscription can be Closed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            if (! Schema::hasColumn('subscriptions', 'contract_signed_at')) {
                $table->date('contract_signed_at')->nullable()->after('started_at');
            }
            if (! Schema::hasColumn('subscriptions', 'renewal_year')) {
                $table->unsignedSmallInteger('renewal_year')->nullable()->after('contract_signed_at');
            }
            if (! Schema::hasColumn('subscriptions', 'vas_license_expires_at')) {
                $table->date('vas_license_expires_at')->nullable()->after('renewal_year');
            }
            if (! Schema::hasColumn('subscriptions', 'closed_at')) {
                $table->timestamp('closed_at')->nullable()->after('terminated_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $cols = array_values(array_filter([
                Schema::hasColumn('subscriptions', 'contract_signed_at') ? 'contract_signed_at' : null,
                Schema::hasColumn('subscriptions', 'renewal_year') ? 'renewal_year' : null,
                Schema::hasColumn('subscriptions', 'vas_license_expires_at') ? 'vas_license_expires_at' : null,
                Schema::hasColumn('subscriptions', 'closed_at') ? 'closed_at' : null,
            ]));

            if ($cols !== []) {
                $table->dropColumn($cols);
            }
        });
    }
};
