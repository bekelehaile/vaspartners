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
        $partners = database_path(SeedRevenueExcelSnapshotService::PARTNERS_FILE);
        $rows = database_path(SeedRevenueExcelSnapshotService::ROWS_FILE);
        if (! is_file($partners) || ! is_file($rows)) {
            // Stage / fresh envs without finance snapshots — skip; prod already applied.
            return;
        }

        $seeder = app(SeedRevenueExcelSnapshotService::class);
        $seeder->seedPartners();
        $seeder->seedMonthlyImports();
    }

    public function down(): void
    {
        // Keep seeded business data on rollback; use vas:seed-revenue-excel to refresh.
    }
};
