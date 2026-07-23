<?php

use App\Models\Requisition;
use App\Models\Service;
use App\Models\Ticket;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $keep = Requisition::query()->where('code', 'new')->first();
        $remove = Requisition::withTrashed()->where('code', 'new-request')->first();

        if (! $keep) {
            // Promote new-request to canonical "New subscription" if that is all we have.
            if ($remove) {
                $remove->forceFill([
                    'name' => 'New subscription',
                    'code' => 'new',
                    'slug' => 'new',
                    'creates_subscription' => true,
                    'requires_active_subscription' => false,
                    'is_active' => true,
                    'is_system' => true,
                    'deleted_at' => null,
                ])->save();
            }

            return;
        }

        $keep->forceFill([
            'name' => 'New subscription',
            'slug' => 'new',
            'creates_subscription' => true,
            'is_active' => true,
            'is_system' => true,
        ])->save();

        if (! $remove || $remove->id === $keep->id) {
            return;
        }

        $fromId = $remove->id;
        $toId = $keep->id;

        Ticket::withTrashed()
            ->where('requisition_id', $fromId)
            ->update(['requisition_id' => $toId]);

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
        $remove->delete();
    }

    public function down(): void
    {
        // Irreversible merge — intentionally empty.
    }
};
