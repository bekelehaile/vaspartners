<?php

use App\Enums\CompanyMemberPermission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Split legacy create_service_requests into create_subscriptions + manage_services.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('company_memberships', 'permissions')) {
            return;
        }

        $rows = DB::table('company_memberships')
            ->whereNotNull('permissions')
            ->select(['id', 'permissions'])
            ->get();

        foreach ($rows as $row) {
            $decoded = json_decode((string) $row->permissions, true);
            if (! is_array($decoded)) {
                continue;
            }

            $normalized = CompanyMemberPermission::normalizeStored(
                array_map('strval', $decoded)
            );
            $allowed = CompanyMemberPermission::allValues();
            $filtered = array_values(array_intersect($normalized, $allowed));

            if ($filtered === array_values(array_map('strval', $decoded))) {
                continue;
            }

            DB::table('company_memberships')->where('id', $row->id)->update([
                'permissions' => json_encode(array_values($filtered)),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('company_memberships', 'permissions')) {
            return;
        }

        $rows = DB::table('company_memberships')
            ->whereNotNull('permissions')
            ->select(['id', 'permissions'])
            ->get();

        foreach ($rows as $row) {
            $decoded = json_decode((string) $row->permissions, true);
            if (! is_array($decoded)) {
                continue;
            }

            $keys = array_map('strval', $decoded);
            $hasCreate = in_array(CompanyMemberPermission::CreateSubscriptions->value, $keys, true);
            $hasManage = in_array(CompanyMemberPermission::ManageServices->value, $keys, true);

            if (! $hasCreate && ! $hasManage) {
                continue;
            }

            $keys = array_values(array_filter(
                $keys,
                fn (string $k) => ! in_array($k, [
                    CompanyMemberPermission::CreateSubscriptions->value,
                    CompanyMemberPermission::ManageServices->value,
                ], true)
            ));

            if ($hasCreate || $hasManage) {
                $keys[] = 'create_service_requests';
            }

            DB::table('company_memberships')->where('id', $row->id)->update([
                'permissions' => json_encode(array_values(array_unique($keys))),
                'updated_at' => now(),
            ]);
        }
    }
};
