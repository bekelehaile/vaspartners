<?php

namespace App\Filament\Resources\DocumentTypes\Pages;

use App\Filament\Resources\DocumentTypes\DocumentTypeResource;
use App\Models\DocumentType;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;

class CreateDocumentType extends CreateRecord
{
    protected static string $resource = DocumentTypeResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['code'] = $this->uniqueCodeFromName((string) ($data['name'] ?? 'document'));

        return $data;
    }

    protected function uniqueCodeFromName(string $name): string
    {
        $base = Str::slug($name) ?: 'document';
        $code = $base;
        $i = 1;

        while (DocumentType::query()->where('code', $code)->exists()) {
            $code = $base.'-'.$i;
            $i++;
        }

        return $code;
    }
}
