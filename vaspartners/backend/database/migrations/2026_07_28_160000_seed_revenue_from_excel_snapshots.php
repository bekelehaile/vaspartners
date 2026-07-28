<?php

use App\Services\Migration\SeedRevenueExcelSnapshotService;
use Illuminate\Database\Migrations\Migration;

/**
 * Actual data migration: load finance Excel snapshots into revenue tables.
 * Snapshots are committed JSON (no XLSX parse at migrate time).
 * Re-run safely via: php artisan vas:seed-revenue-excel
 */
return new class extends Migration
{
    public function up(): void
    {
        $seeder = app(SeedRevenueExcelSnapshotService::class);
        $seeder->seedPartners();
        $seeder->seedMonthlyImports();
    }

    public function down(): void
    {
        // Keep seeded business data on rollback; use vas:seed-revenue-excel to refresh.
    }
};
