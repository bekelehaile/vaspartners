<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Removes catalog-filter columns if they were added earlier (misunderstood feature).
 */
return new class extends Migration
{
    public function up(): void
    {
        $drops = [];
        if (Schema::hasColumn('bulk_messages', 'source')) {
            $drops[] = 'source';
        }
        if (Schema::hasColumn('bulk_messages', 'filters')) {
            $drops[] = 'filters';
        }

        if ($drops === []) {
            return;
        }

        Schema::table('bulk_messages', function (Blueprint $table) use ($drops): void {
            $table->dropColumn($drops);
        });
    }

    public function down(): void
    {
        Schema::table('bulk_messages', function (Blueprint $table): void {
            if (! Schema::hasColumn('bulk_messages', 'source')) {
                $table->string('source', 32)->default('import')->after('message');
            }
            if (! Schema::hasColumn('bulk_messages', 'filters')) {
                $table->json('filters')->nullable()->after('source_path');
            }
        });
    }
};
