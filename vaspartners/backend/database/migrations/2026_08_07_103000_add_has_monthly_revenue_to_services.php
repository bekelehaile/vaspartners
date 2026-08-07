<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Flag services that appear in Monthly Revenue catalog filters.
 * Premium services default to true; others can be enabled manually.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            if (! Schema::hasColumn('services', 'has_monthly_revenue')) {
                $table->boolean('has_monthly_revenue')->default(false)->after('is_active');
            }
        });

        // Default: all premium catalog services (active or inactive) participate in monthly revenue.
        DB::table('services')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get(['id', 'slug', 'name'])
            ->each(function (object $row): void {
                $haystack = strtolower(trim(($row->slug ?? '').' '.($row->name ?? '')));
                if ($haystack === '') {
                    return;
                }
                if (
                    str_contains($haystack, 'non-premium')
                    || str_contains($haystack, 'non_premium')
                    || str_contains($haystack, 'non premium')
                ) {
                    return;
                }
                if (! str_contains($haystack, 'premium')) {
                    return;
                }

                DB::table('services')->where('id', $row->id)->update(['has_monthly_revenue' => true]);
            });
    }

    public function down(): void
    {
        if (Schema::hasColumn('services', 'has_monthly_revenue')) {
            Schema::table('services', function (Blueprint $table): void {
                $table->dropColumn('has_monthly_revenue');
            });
        }
    }
};
