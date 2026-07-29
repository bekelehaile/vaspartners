<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Partners may own multiple companies (unique TIN each). Company phone/email
 * come from the contact identity and may repeat across those companies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropUnique('companies_phone_unique');
            $table->dropUnique('companies_email_unique');
        });

        Schema::table('companies', function (Blueprint $table): void {
            $table->index('phone', 'companies_phone_index');
            $table->index('email', 'companies_email_index');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropIndex('companies_phone_index');
            $table->dropIndex('companies_email_index');
        });

        // Clear duplicate phones/emails so unique indexes can be restored.
        $this->nullDuplicateNonNull('companies', 'phone');
        $this->nullDuplicateNonNull('companies', 'email');

        Schema::table('companies', function (Blueprint $table): void {
            $table->unique('phone', 'companies_phone_unique');
            $table->unique('email', 'companies_email_unique');
        });
    }

    private function nullDuplicateNonNull(string $table, string $column): void
    {
        $duplicates = DB::table($table)
            ->select($column)
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->groupBy($column)
            ->havingRaw('COUNT(*) > 1')
            ->pluck($column);

        foreach ($duplicates as $value) {
            $ids = DB::table($table)->where($column, $value)->orderBy('id')->pluck('id');
            $keep = $ids->shift();
            if ($ids->isEmpty()) {
                continue;
            }
            DB::table($table)->whereIn('id', $ids)->update([$column => null]);
            unset($keep);
        }
    }
};
