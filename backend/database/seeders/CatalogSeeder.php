<?php

namespace Database\Seeders;

use App\Enums\RenewalInterval;
use App\Models\Category;
use App\Models\DocumentType;
use App\Models\Requisition;
use App\Models\Service;
use App\Models\ServiceRequisitionDocument;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        $requisitions = [
            ['code' => 'new', 'name' => 'New subscription', 'creates_subscription' => true],
            ['code' => 'renew', 'name' => 'Renewal', 'requires_active_subscription' => true, 'renews_subscription' => true],
            ['code' => 'move', 'name' => 'Move', 'requires_active_subscription' => true],
            ['code' => 'upgrade', 'name' => 'Upgrade', 'requires_active_subscription' => true],
            ['code' => 'downgrade', 'name' => 'Downgrade', 'requires_active_subscription' => true],
            ['code' => 'relocate', 'name' => 'Relocate', 'requires_active_subscription' => true],
            ['code' => 'maintenance', 'name' => 'Maintenance', 'requires_active_subscription' => true],
            ['code' => 'terminate', 'name' => 'Terminate', 'requires_active_subscription' => true, 'terminates_subscription' => true],
            ['code' => 'other', 'name' => 'Other', 'description' => 'Catch-all configurable request type'],
        ];

        $sort = 0;
        foreach ($requisitions as $row) {
            Requisition::query()->updateOrCreate(
                ['code' => $row['code']],
                [
                    'name' => $row['name'],
                    'slug' => Str::slug($row['name']),
                    'description' => $row['description'] ?? null,
                    'is_active' => true,
                    'sort_order' => $sort++,
                    'creates_subscription' => $row['creates_subscription'] ?? false,
                    'requires_active_subscription' => $row['requires_active_subscription'] ?? false,
                    'renews_subscription' => $row['renews_subscription'] ?? false,
                    'terminates_subscription' => $row['terminates_subscription'] ?? false,
                    'is_system' => true,
                ]
            );
        }

        $docTypes = [
            ['code' => 'trade_license', 'name' => 'Trade license'],
            ['code' => 'tin_certificate', 'name' => 'TIN certificate'],
            ['code' => 'application_letter', 'name' => 'Application letter'],
            ['code' => 'id_copy', 'name' => 'ID copy'],
            ['code' => 'site_plan', 'name' => 'Site / location plan'],
            ['code' => 'termination_letter', 'name' => 'Termination letter'],
            ['code' => 'renewal_form', 'name' => 'Renewal form'],
            ['code' => 'upgrade_justification', 'name' => 'Upgrade justification'],
        ];

        foreach ($docTypes as $row) {
            DocumentType::query()->updateOrCreate(
                ['code' => $row['code']],
                [
                    'name' => $row['name'],
                    'accepted_mimes' => 'pdf,doc,docx,png,jpg,jpeg',
                    'max_size_kb' => 5120,
                    'is_active' => true,
                ]
            );
        }

        $category = Category::query()->updateOrCreate(
            ['slug' => 'mvas'],
            [
                'name' => 'MVAS',
                'description' => 'Mobile Value Added Services',
                'is_active' => true,
                'sort_order' => 1,
            ]
        );

        $renew = Requisition::query()->where('code', 'renew')->first();

        $service = Service::query()->updateOrCreate(
            ['slug' => 'sample-vas'],
            [
                'category_id' => $category->id,
                'name' => 'Sample VAS',
                'description' => 'Example subscription-based service for local testing',
                'is_active' => true,
                'is_subscription_based' => true,
                'renewal_interval' => RenewalInterval::Yearly,
                'renewal_lead_days' => 30,
                'renewal_requisition_id' => $renew?->id,
                'sort_order' => 1,
            ]
        );

        $allRequisitionIds = Requisition::query()->where('is_system', true)->pluck('id');
        $service->requisitions()->sync($allRequisitionIds);

        $matrix = [
            'new' => ['trade_license', 'tin_certificate', 'application_letter', 'id_copy'],
            'renew' => ['renewal_form', 'trade_license'],
            'move' => ['application_letter', 'site_plan'],
            'upgrade' => ['application_letter', 'upgrade_justification'],
            'downgrade' => ['application_letter'],
            'relocate' => ['application_letter', 'site_plan'],
            'maintenance' => ['application_letter'],
            'terminate' => ['termination_letter', 'id_copy'],
            'other' => ['application_letter'],
        ];

        foreach ($matrix as $reqCode => $docCodes) {
            $requisition = Requisition::query()->where('code', $reqCode)->first();
            if (! $requisition) {
                continue;
            }

            $order = 0;
            foreach ($docCodes as $docCode) {
                $docType = DocumentType::query()->where('code', $docCode)->first();
                if (! $docType) {
                    continue;
                }

                ServiceRequisitionDocument::query()->updateOrCreate(
                    [
                        'service_id' => $service->id,
                        'requisition_id' => $requisition->id,
                        'document_type_id' => $docType->id,
                    ],
                    [
                        'is_required' => true,
                        'sort_order' => $order++,
                    ]
                );
            }
        }
    }
}
