<?php

namespace Database\Seeders;

use App\Models\DocumentType;
use App\Models\ServiceRequisitionDocument;
use Illuminate\Database\Seeder;

/** Marks "Document if any" matrix rows as optional (still attachable). */
class OptionalDocumentIfAnySeeder extends Seeder
{
    public function run(): void
    {
        $ids = DocumentType::query()
            ->where(function ($q) {
                $q->where('code', 'document-if-any')
                    ->orWhere('name', 'like', '%if any%');
            })
            ->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        ServiceRequisitionDocument::query()
            ->whereIn('document_type_id', $ids)
            ->update(['is_required' => false]);

        DocumentType::query()
            ->whereIn('id', $ids)
            ->update([
                'description' => 'Optional supporting document — attach only if available.',
            ]);
    }
}
