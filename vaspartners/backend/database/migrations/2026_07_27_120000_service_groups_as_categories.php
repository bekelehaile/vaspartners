<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Repurpose categories as two renameable operational groups (Group 1 / Group 2).
 * Services may belong to one or both via category_service; tickets keep a single
 * category_id for routing / staff scoping.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->string('key', 32)->nullable()->unique()->after('id');
        });

        Schema::create('category_service', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['category_id', 'service_id']);
        });

        $now = now();

        $group1Id = $this->upsertGroup('group_1', 'Group 1', 'group-1', 1, $now);
        $group2Id = $this->upsertGroup('group_2', 'Group 2', 'group-2', 2, $now);

        // Map legacy catalog categories → operational groups.
        $legacyToGroup = [
            'vas-sales-group' => $group1Id,
            'startup-partner' => $group1Id,
            'other-service' => $group1Id,
            'enterprise-solution' => $group1Id,
            'marketing' => $group1Id,
            'advanced-vas-sales-group' => $group2Id,
            'fintech' => $group2Id,
            'group-1' => $group1Id,
            'group-2' => $group2Id,
        ];

        $categoryRows = DB::table('categories')->select('id', 'slug', 'key')->get();
        $idToGroup = [];
        foreach ($categoryRows as $row) {
            if ($row->key === 'group_1') {
                $idToGroup[(int) $row->id] = $group1Id;
            } elseif ($row->key === 'group_2') {
                $idToGroup[(int) $row->id] = $group2Id;
            } else {
                $idToGroup[(int) $row->id] = $legacyToGroup[$row->slug] ?? $group1Id;
            }
        }

        // Remap services + backfill pivot.
        $services = DB::table('services')->select('id', 'category_id')->get();
        foreach ($services as $service) {
            $oldCategoryId = (int) $service->category_id;
            $groupId = $idToGroup[$oldCategoryId] ?? $group1Id;
            if ($oldCategoryId !== $groupId) {
                DB::table('services')->where('id', $service->id)->update(['category_id' => $groupId]);
            }
            DB::table('category_service')->updateOrInsert(
                ['category_id' => $groupId, 'service_id' => $service->id],
                ['created_at' => $now, 'updated_at' => $now],
            );
        }

        // Remap tickets to the new group ids.
        $tickets = DB::table('tickets')->select('id', 'category_id')->get();
        foreach ($tickets as $ticket) {
            $old = (int) $ticket->category_id;
            $groupId = $idToGroup[$old] ?? $group1Id;
            if ($old !== $groupId) {
                DB::table('tickets')->where('id', $ticket->id)->update(['category_id' => $groupId]);
            }
        }

        // Remap staff category_user rows onto groups (dedupe).
        $staffLinks = DB::table('category_user')->get();
        $seen = [];
        foreach ($staffLinks as $link) {
            $groupId = $idToGroup[(int) $link->category_id] ?? $group1Id;
            $key = $groupId.':'.$link->user_id;
            if (isset($seen[$key])) {
                DB::table('category_user')->where('id', $link->id)->delete();

                continue;
            }
            $seen[$key] = true;
            if ((int) $link->category_id !== $groupId) {
                // Avoid unique conflicts: delete then insert.
                DB::table('category_user')->where('id', $link->id)->delete();
                DB::table('category_user')->updateOrInsert(
                    ['category_id' => $groupId, 'user_id' => $link->user_id],
                    [],
                );
            }
        }

        // Soft-delete legacy non-group categories (keep FK history via soft deletes).
        DB::table('categories')
            ->whereNull('key')
            ->update([
                'is_active' => false,
                'deleted_at' => $now,
                'updated_at' => $now,
            ]);

        // Point FAQs off soft-deleted categories if any.
        DB::table('faqs')
            ->whereNotNull('category_id')
            ->whereNotIn('category_id', [$group1Id, $group2Id])
            ->update(['category_id' => null]);
    }

    public function down(): void
    {
        Schema::dropIfExists('category_service');

        Schema::table('categories', function (Blueprint $table) {
            $table->dropUnique(['key']);
            $table->dropColumn('key');
        });
    }

    private function upsertGroup(string $key, string $name, string $slug, int $sort, $now): int
    {
        $existing = DB::table('categories')->where('key', $key)->first()
            ?? DB::table('categories')->where('slug', $slug)->first();

        if ($existing) {
            DB::table('categories')->where('id', $existing->id)->update([
                'key' => $key,
                'name' => $existing->name ?: $name,
                'slug' => $slug,
                'is_active' => true,
                'sort_order' => $sort,
                'deleted_at' => null,
                'updated_at' => $now,
            ]);

            return (int) $existing->id;
        }

        return (int) DB::table('categories')->insertGetId([
            'key' => $key,
            'name' => $name,
            'slug' => $slug,
            'description' => 'Operational service group. Rename as needed (e.g. Team 1).',
            'is_active' => true,
            'sort_order' => $sort,
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ]);
    }
};
