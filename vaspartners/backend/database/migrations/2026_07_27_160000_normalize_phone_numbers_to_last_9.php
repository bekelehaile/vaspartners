<?php

use App\Support\PhoneNumber;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Normalize stored phones to last 9 digits for contacts, users, and companies.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->normalizeTable('contacts', 'phone_number', unique: true);
        $this->normalizeTable('contacts', 'company_phone', unique: false);
        $this->normalizeTable('users', 'phone', unique: true);
        $this->normalizeTable('companies', 'phone', unique: false);

        if (Schema::hasTable('bulk_message_recipients') && Schema::hasColumn('bulk_message_recipients', 'phone_normalized')) {
            $this->normalizeTable('bulk_message_recipients', 'phone_normalized', unique: false);
        }
    }

    public function down(): void
    {
        // Irreversible normalization.
    }

    protected function normalizeTable(string $table, string $column, bool $unique): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        $seen = [];

        DB::table($table)
            ->orderBy('id')
            ->get(['id', $column])
            ->each(function ($row) use ($table, $column, $unique, &$seen): void {
                $raw = $row->{$column};
                if ($raw === null || trim((string) $raw) === '') {
                    return;
                }

                $normalized = PhoneNumber::normalizeNullable($raw);
                if ($normalized === null || $normalized === (string) $raw) {
                    if ($normalized !== null) {
                        $seen[$normalized] = true;
                    }

                    return;
                }

                if ($unique && isset($seen[$normalized])) {
                    // Keep the first row; blank duplicates so unique indexes stay valid.
                    DB::table($table)->where('id', $row->id)->update([$column => null]);

                    return;
                }

                if ($unique) {
                    $clash = DB::table($table)
                        ->where($column, $normalized)
                        ->where('id', '!=', $row->id)
                        ->exists();
                    if ($clash) {
                        DB::table($table)->where('id', $row->id)->update([$column => null]);

                        return;
                    }
                    $seen[$normalized] = true;
                }

                DB::table($table)->where('id', $row->id)->update([$column => $normalized]);
            });
    }
};
