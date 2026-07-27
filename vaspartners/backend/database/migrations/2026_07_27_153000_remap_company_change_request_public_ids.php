<?php

use App\Support\TimestampPublicId;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Remap ULID company change-request numbers to YmdH + two digits (e.g. 202607270879).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('company_change_requests')) {
            return;
        }

        $used = DB::table('company_change_requests')->pluck('public_id')->flip()->all();

        DB::table('company_change_requests')
            ->orderBy('id')
            ->get(['id', 'public_id', 'created_at'])
            ->each(function ($row) use (&$used): void {
                if (! TimestampPublicId::looksLikeUlid($row->public_id)) {
                    return;
                }

                $newId = TimestampPublicId::generate(
                    $row->created_at,
                    fn (string $id): bool => isset($used[$id]),
                );

                $used[$newId] = true;

                DB::table('company_change_requests')
                    ->where('id', $row->id)
                    ->update(['public_id' => $newId]);
            });
    }

    public function down(): void
    {
        // Irreversible remap — ULIDs are not reconstructed.
    }
};
