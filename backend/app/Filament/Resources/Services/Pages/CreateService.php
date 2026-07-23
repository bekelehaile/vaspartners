<?php

namespace App\Filament\Resources\Services\Pages;

use App\Enums\RenewalInterval;
use App\Filament\Resources\Services\ServiceResource;
use App\Models\Service;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;

class CreateService extends CreateRecord
{
    protected static string $resource = ServiceResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['slug'] = static::uniqueSlugFromName((string) ($data['name'] ?? 'service'), $data['slug'] ?? null);

        return static::normalizeSubscriptionFields($data);
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
