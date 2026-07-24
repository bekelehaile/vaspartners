<?php

namespace App\Filament\Resources\Requisitions\Pages;

use App\Filament\Resources\Requisitions\RequisitionResource;
use App\Models\Requisition;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;

class CreateRequisition extends CreateRecord
{
    protected static string $resource = RequisitionResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $base = Str::slug((string) ($data['name'] ?? 'request')) ?: 'request';
        $data['slug'] = static::uniqueValue('slug', $base);
        $data['code'] = static::uniqueValue('code', $data['code'] ?? $base);

        return $data;
    }

    protected static function uniqueValue(string $column, string $base): string
    {
        $value = $base;
        $i = 1;

        while (Requisition::query()->where($column, $value)->exists()) {
            $value = $base.'-'.$i;
            $i++;
        }

        return $value;
    }
}
