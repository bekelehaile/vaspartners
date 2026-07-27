<?php

namespace App\Filament\Resources\Categories\Pages;

use App\Filament\Resources\Categories\CategoryResource;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;

class EditCategory extends EditRecord
{
    protected static string $resource = CategoryResource::class;

    public function getTitle(): string|Htmlable
    {
        return 'Rename '.$this->getRecord()->name;
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
