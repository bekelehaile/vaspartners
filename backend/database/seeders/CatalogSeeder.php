<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\DocumentType;
use App\Models\Faq;
use App\Models\Priority;
use App\Models\Requisition;
use App\Models\Service;
use App\Models\ServiceRequisitionDocument;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('data/mvas_catalog.json');
        if (! File::exists($path)) {
            $this->command?->warn('Missing database/data/mvas_catalog.json — skipping catalog import.');

            return;
        }

        $data = json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);

        $categoryMap = [];
        foreach ($data['categories'] as $row) {
            $category = Category::query()->updateOrCreate(
                ['slug' => $row['slug']],
                [
                    'name' => $row['name'],
                    'description' => null,
                    'is_active' => (bool) ($row['is_active'] ?? true),
                    'sort_order' => (int) ($row['sort_order'] ?? 0),
                ]
            );
            $categoryMap[(int) $row['legacy_id']] = $category->id;
        }

        $priorityRows = $data['priorities'] ?? [];
        foreach ($priorityRows as $row) {
            Priority::query()->updateOrCreate(
                ['code' => $row['code']],
                [
                    'name' => $row['name'],
                    'weight' => (int) ($row['weight'] ?? 0),
                    'color' => $row['color'] ?? null,
                    'is_active' => true,
                ]
            );
        }

        $requisitionMap = [];
        foreach ($data['requisitions'] as $row) {
            $requisition = Requisition::query()->updateOrCreate(
                ['code' => $row['code']],
                [
                    'name' => $row['name'],
                    'slug' => $row['slug'],
                    'description' => null,
                    'is_active' => true,
                    'sort_order' => (int) ($row['sort_order'] ?? 0),
                    'creates_subscription' => (bool) ($row['creates_subscription'] ?? false),
                    'requires_active_subscription' => (bool) ($row['requires_active_subscription'] ?? false),
                    'renews_subscription' => (bool) ($row['renews_subscription'] ?? false),
                    'terminates_subscription' => (bool) ($row['terminates_subscription'] ?? false),
                    'is_system' => (bool) ($row['is_system'] ?? true),
                ]
            );
            $requisitionMap[(int) $row['legacy_id']] = $requisition->id;
        }

        $documentMap = [];
        foreach ($data['document_types'] as $row) {
            $document = DocumentType::query()->updateOrCreate(
                ['code' => $row['code']],
                [
                    'name' => $row['name'],
                    'accepted_mimes' => $row['accepted_mimes'] ?? 'pdf,doc,docx,png,jpg,jpeg',
                    'max_size_kb' => (int) ($row['max_size_kb'] ?? 2048),
                    'description' => $row['description'] ?? null,
                    'is_active' => true,
                ]
            );
            $documentMap[(int) $row['legacy_id']] = $document->id;
        }

        $serviceMap = [];
        foreach ($data['services'] as $row) {
            $categoryId = $categoryMap[(int) $row['legacy_category_id']] ?? null;
            if (! $categoryId) {
                continue;
            }

            $service = Service::query()->updateOrCreate(
                ['slug' => $row['slug']],
                [
                    'category_id' => $categoryId,
                    'name' => $row['name'],
                    'description' => $row['description'] ?? null,
                    'type' => $row['type'] ?? null,
                    'is_active' => (bool) ($row['is_active'] ?? true),
                    'is_subscription_based' => true,
                    'sort_order' => (int) ($row['sort_order'] ?? 0),
                ]
            );
            $serviceMap[(int) $row['legacy_id']] = $service->id;
        }

        $syncByService = [];
        foreach ($data['requisition_services'] as $row) {
            $serviceId = $serviceMap[(int) $row['legacy_service_id']] ?? null;
            $requisitionId = $requisitionMap[(int) $row['legacy_requisition_id']] ?? null;
            if (! $serviceId || ! $requisitionId) {
                continue;
            }
            $syncByService[$serviceId][] = $requisitionId;
        }

        foreach ($syncByService as $serviceId => $requisitionIds) {
            Service::query()->find($serviceId)?->requisitions()->sync(array_values(array_unique($requisitionIds)));
        }

        // Fallback: any service without pivots gets "New request" when present.
        $newRequestId = Requisition::query()->where('code', 'new-request')->value('id');
        if ($newRequestId) {
            foreach ($serviceMap as $serviceId) {
                $service = Service::query()->find($serviceId);
                if ($service && $service->requisitions()->count() === 0) {
                    $service->requisitions()->sync([$newRequestId]);
                }
            }
        }

        foreach ($data['document_matrix'] as $row) {
            $serviceId = $serviceMap[(int) $row['legacy_service_id']] ?? null;
            $requisitionId = $requisitionMap[(int) $row['legacy_requisition_id']] ?? null;
            if (! $serviceId || ! $requisitionId) {
                continue;
            }

            $order = 0;
            foreach ($row['legacy_document_type_ids'] as $legacyDocId) {
                $documentTypeId = $documentMap[(int) $legacyDocId] ?? null;
                if (! $documentTypeId) {
                    continue;
                }

                ServiceRequisitionDocument::query()->updateOrCreate(
                    [
                        'service_id' => $serviceId,
                        'requisition_id' => $requisitionId,
                        'document_type_id' => $documentTypeId,
                    ],
                    [
                        'is_required' => true,
                        'sort_order' => $order++,
                    ]
                );
            }
        }

        foreach ($data['faqs'] as $row) {
            Faq::query()->updateOrCreate(
                ['question' => $row['question']],
                [
                    'answer' => $row['answer'],
                    'category_id' => null,
                    'is_active' => true,
                    'sort_order' => (int) ($row['sort_order'] ?? 0),
                ]
            );
        }

        // Remove sample placeholder if present.
        Service::query()->where('slug', 'sample-vas')->delete();
        Category::query()->where('slug', 'mvas')->whereDoesntHave('services')->delete();
    }
}
