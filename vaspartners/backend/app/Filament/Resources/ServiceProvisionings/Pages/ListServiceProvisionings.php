<?php

namespace App\Filament\Resources\ServiceProvisionings\Pages;

use App\Filament\Resources\ServiceProvisionings\ServiceProvisioningResource;
use Filament\Resources\Pages\ListRecords;

class ListServiceProvisionings extends ListRecords
{
    protected static string $resource = ServiceProvisioningResource::class;

    public function getTitle(): string
    {
        return 'Service activation';
    }

    public function getSubheading(): ?string
    {
        return 'Track activation cases (PRV), overall time, and department queues.';
    }
}
