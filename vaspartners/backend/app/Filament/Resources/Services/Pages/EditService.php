<?php

namespace App\Filament\Resources\Services\Pages;

use App\Filament\Resources\Services\ServiceResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;

class EditService extends EditRecord
{
    protected static string $resource = ServiceResource::class;

    /** @var list<int> */
    protected array $pendingGroupIds = [];

    public function getTitle(): string|Htmlable
    {
        return 'Edit '.$this->getRecord()->name;
    }

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['group_ids'] = $this->getRecord()
            ->categories()
            ->orderBy('sort_order')
            ->pluck('categories.id')
            ->all();

        if ($data['group_ids'] === [] && ! empty($data['category_id'])) {
            $data['group_ids'] = [(int) $data['category_id']];
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data = CreateService::normalizeSubscriptionFields($data);

        return CreateService::extractPrimaryCategory($data, $this->pendingGroupIds);
    }

    protected function afterSave(): void
    {
        if ($this->pendingGroupIds !== []) {
            $this->record->syncGroups($this->pendingGroupIds);
        }
    }
}
