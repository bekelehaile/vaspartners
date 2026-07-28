<?php

namespace App\Support;

use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Collection;

final class RevenueCatalogServices
{
    /**
     * Active catalog services available for revenue mapping.
     *
     * @return Collection<int, Service>
     */
    public static function query(?User $user = null): Collection
    {
        $q = Service::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name');

        if ($user && ! $user->canAccessAllRevenue()) {
            $ids = $user->managedRevenueServiceIds();
            if ($ids === []) {
                return collect();
            }
            $q->whereIn('id', $ids);
        }

        return $q->get();
    }

    /**
     * @return array<int, string>
     */
    public static function options(?User $user = null): array
    {
        return self::query($user)
            ->mapWithKeys(fn (Service $service) => [$service->id => $service->name])
            ->all();
    }
}
