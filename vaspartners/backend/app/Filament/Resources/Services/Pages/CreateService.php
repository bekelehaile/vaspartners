<?php

namespace App\Filament\Resources\Services\Pages;

use App\Enums\RenewalInterval;
use App\Filament\Resources\Services\ServiceResource;
use App\Models\Category;
use App\Models\Service;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateService extends CreateRecord
{
    protected static string $resource = ServiceResource::class;

    /** @var list<int> */
    protected array $pendingGroupIds = [];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['slug'] = static::uniqueSlugFromName((string) ($data['name'] ?? 'service'), $data['slug'] ?? null);
        $data = static::normalizeSubscriptionFields($data);
        $data = static::extractPrimaryCategory($data, $this->pendingGroupIds);

        return $data;
    }

    protected function afterCreate(): void
    {
        if ($this->pendingGroupIds !== []) {
            $this->record->syncGroups($this->pendingGroupIds);
        }
    }

    public static function uniqueSlugFromName(string $name, ?string $preferred = null): string
    {
        $base = Str::slug($preferred ?: $name) ?: 'service';
        $slug = $base;
        $i = 1;

        while (Service::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i;
            $i++;
        }

        return $slug;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<int>  $pendingGroupIds
     * @return array<string, mixed>
     */
    public static function extractPrimaryCategory(array $data, array &$pendingGroupIds): array
    {
        $ids = collect($data['group_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();
        unset($data['group_ids']);

        $valid = Category::query()
            ->operationalGroups()
            ->whereIn('id', $ids)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($valid === []) {
            throw ValidationException::withMessages([
                'group_ids' => 'Select at least one group (Group 1 and/or Group 2).',
            ]);
        }

        $pendingGroupIds = $valid;
        $data['category_id'] = $valid[0];

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalizeSubscriptionFields(array $data): array
    {
        if (empty($data['is_subscription_based'])) {
            $data['is_subscription_based'] = false;
            $data['renewal_interval'] = null;
            $data['renewal_lead_days'] = 30;
            $data['renewal_requisition_id'] = null;

            return $data;
        }

        $data['is_subscription_based'] = true;
        $data['renewal_interval'] = $data['renewal_interval'] ?? RenewalInterval::Yearly->value;
        $data['renewal_lead_days'] = $data['renewal_lead_days'] ?? 30;

        return $data;
    }
}
