<?php

use App\Enums\ErcaNameStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Clear admin-only tin_validated flags. TIN OK is ERCA-confirmed only.
 */
return new class extends Migration
{
    public function up(): void
    {
        $resolved = [
            ErcaNameStatus::Matched->value,
            ErcaNameStatus::AcceptedLegal->value,
            ErcaNameStatus::KeptBoth->value,
        ];

        DB::table('companies')
            ->where('tin_validated', true)
            ->where(function ($q) use ($resolved): void {
                $q->where('erca_tin_verified', false)
                    ->orWhereNull('erca_name_status')
                    ->orWhereNotIn('erca_name_status', $resolved);
            })
            ->update([
                'tin_validated' => false,
                'tin_validated_by_user_id' => null,
                'tin_validated_at' => null,
                'updated_at' => now(),
            ]);

        // Keep cached flag aligned for ERCA-resolved companies.
        DB::table('companies')
            ->where('erca_tin_verified', true)
            ->whereIn('erca_name_status', $resolved)
            ->where('tin_validated', false)
            ->update([
                'tin_validated' => true,
                'tin_validated_by_user_id' => null,
                'tin_validated_at' => DB::raw('COALESCE(tin_validated_at, erca_verified_at, NOW())'),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Irreversible data correction.
    }
};
