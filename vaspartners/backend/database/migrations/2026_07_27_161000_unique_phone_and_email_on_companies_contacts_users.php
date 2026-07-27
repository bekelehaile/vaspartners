<?php

use App\Support\EmailAddress;
use App\Support\PhoneNumber;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ensure phone + email uniqueness on contacts, users, and companies.
 * Normalizes values first; blanks duplicate non-null values so unique indexes can apply.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->normalizeAndDedupe('contacts', 'phone_number', 'phone');
        $this->normalizeAndDedupe('contacts', 'email', 'email');
        $this->normalizeAndDedupe('users', 'phone', 'phone');
        $this->normalizeAndDedupe('users', 'email', 'email');
        $this->normalizeAndDedupe('companies', 'phone', 'phone');
        $this->normalizeAndDedupe('companies', 'email', 'email');

        $this->ensureUniqueIndex('contacts', 'phone_number', 'contacts_phone_number_unique');
        $this->ensureUniqueIndex('contacts', 'email', 'contacts_email_unique');
        $this->ensureUniqueIndex('users', 'phone', 'users_phone_unique');
        $this->ensureUniqueIndex('users', 'email', 'users_email_unique');
        $this->ensureUniqueIndex('companies', 'phone', 'companies_phone_unique');
        $this->ensureUniqueIndex('companies', 'email', 'companies_email_unique');
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropUnique('companies_phone_unique');
            $table->dropUnique('companies_email_unique');
        });
    }

    protected function normalizeAndDedupe(string $table, string $column, string $kind): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        $seen = [];

        DB::table($table)
            ->orderBy('id')
            ->get(['id', $column])
            ->each(function ($row) use ($table, $column, $kind, &$seen): void {
                $raw = $row->{$column};
                $normalized = $kind === 'phone'
                    ? PhoneNumber::normalizeNullable($raw)
                    : EmailAddress::normalize($raw);

                if ($normalized === null) {
                    if ($raw !== null && (string) $raw !== '') {
                        DB::table($table)->where('id', $row->id)->update([$column => null]);
                    }

                    return;
                }

                if (isset($seen[$normalized])) {
                    DB::table($table)->where('id', $row->id)->update([$column => null]);

                    return;
                }

                $clash = DB::table($table)
                    ->where($column, $normalized)
                    ->where('id', '!=', $row->id)
                    ->exists();

                if ($clash) {
                    DB::table($table)->where('id', $row->id)->update([$column => null]);

                    return;
                }

                $seen[$normalized] = true;

                if ((string) $raw !== $normalized) {
                    DB::table($table)->where('id', $row->id)->update([$column => $normalized]);
                }
            });
    }

    protected function ensureUniqueIndex(string $table, string $column, string $indexName): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        $exists = collect(DB::select(
            'SELECT 1 FROM pg_indexes WHERE tablename = ? AND indexname = ?',
            [$table, $indexName],
        ))->isNotEmpty();

        if ($exists) {
            return;
        }

        // Legacy rename left customers_* unique indexes on contacts.
        $legacy = match ($indexName) {
            'contacts_phone_number_unique' => 'customers_phone_number_unique',
            'contacts_email_unique' => 'customers_email_unique',
            default => null,
        };

        if ($legacy) {
            $legacyExists = collect(DB::select(
                'SELECT 1 FROM pg_indexes WHERE tablename = ? AND indexname = ?',
                [$table, $legacy],
            ))->isNotEmpty();
            if ($legacyExists) {
                return;
            }
        }

        Schema::table($table, function (Blueprint $table) use ($column, $indexName) {
            $table->unique($column, $indexName);
        });
    }
};
