<?php

use App\Models\Requisition;
use App\Models\Service;
use App\Models\Ticket;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Merge duplicate request types into the canonical catalog codes.
     *
     * @var array<string, string> from code => keep code
     */
    private array $merges = [
        'new-request' => 'new',
        'terminate' => 'termination',
        'relocate' => 'move',
    ];

    public function up(): void
    {
        foreach ($this->merges as $fromCode => $toCode) {
            $this->mergeInto($fromCode, $toCode);
        }

        // Canonical display names / flags for kept types.
        $this->normalizeKept([
            'new' => [
                'name' => 'New subscription',
                'slug' => 'new',
                'creates_subscription' => true,
                'requires_active_subscription' => false,
                'renews_subscription' => false,
                'terminates_subscription' => false,
            ],
            'termination' => [
                'name' => 'Termination',
                'slug' => 'termination',
                'creates_subscription' => false,
                'requires_active_subscription' => true,
                'renews_subscription' => false,
                'terminates_subscription' => true,
            ],
            'move' => [
                'name' => 'Move',
                'slug' => 'move',
                'creates_subscription' => false,
                'requires_active_subscription' => true,
                'renews_subscription' => false,
                'terminates_subscription' => false,
            ],
        ]);
    }

    public function down(): void
    {
        // Irreversible merges.
    }

    /**
     * @param  array<string, array<string, mixed>>  $rows
     */
    private function normalizeKept(array $rows): void
    {
        foreach ($rows as $code => $attrs) {
            $req = Requisition::query()->where('code', $code)->first();
            if (! $req) {
                continue;
            }

            $req->forceFill(array_merge($attrs, [
                'is_active' => true,
                'is_system' => true,
            ]))->save();
        }
    }

    private function mergeInto(string $fromCode, string $toCode): void
    {
        $keep = Requisition::query()->where('code', $toCode)->first();
        $remove = Requisition::withTrashed()->where('code', $fromCode)->first();

        if (! $keep && $remove) {
            $remove->forceFill([
                'code' => $toCode,
                'is_active' => true,
                'deleted_at' => null,
            ])->save();

            return;
        }

        if (! $keep || ! $remove || $keep->id === $remove->id) {
            return;
        }

        $fromId = $remove->id;
        $toId = $keep->id;

        Ticket::query()
            ->where('requisition_id', $fromId)
            ->update(['requisition_id' => $toId]);

        if (method_exists(Ticket::class, 'withTrashed')) {
            // no-op when SoftDeletes not used on tickets; tickets query above covers active rows
        }

        Service::query()
            ->where('renewal_requisition_id', $fromId)
            ->update(['renewal_requisition_id' => $toId]);

        $serviceIds = DB::table('service_requisition')
            ->where('requisition_id', $fromId)
            ->pluck('service_id');

        foreach ($serviceIds as $serviceId) {
            $exists = DB::table('service_requisition')
                ->where('service_id', $serviceId)
                ->where('requisition_id', $toId)
                ->exists();

            if ($exists) {
                DB::table('service_requisition')
                    ->where('service_id', $serviceId)
                    ->where('requisition_id', $fromId)
                    ->delete();
            } else {
                DB::table('service_requisition')
                    ->where('service_id', $serviceId)
                    ->where('requisition_id', $fromId)
                    ->update(['requisition_id' => $toId]);
            }
        }

        $docs = DB::table('service_requisition_documents')
            ->where('requisition_id', $fromId)
            ->get();

        foreach ($docs as $doc) {
            $exists = DB::table('service_requisition_documents')
                ->where('service_id', $doc->service_id)
                ->where('requisition_id', $toId)
                ->where('document_type_id', $doc->document_type_id)
                ->exists();

            if ($exists) {
                DB::table('service_requisition_documents')->where('id', $doc->id)->delete();
            } else {
                DB::table('service_requisition_documents')
                    ->where('id', $doc->id)
                    ->update(['requisition_id' => $toId]);
            }
        }

        $remove->forceFill([
            'is_active' => false,
            'is_system' => false,
        ])->save();

        if (! $remove->trashed()) {
            $remove->delete();
        }
    }
};
