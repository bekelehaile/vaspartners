<?php

namespace App\Filament\Resources\ServiceProvisionings\Pages;

use App\Filament\Resources\ServiceProvisionings\ServiceProvisioningResource;
use Filament\Resources\Pages\ListRecords;

class ListServiceProvisionings extends ListRecords
{
    protected static string $resource = ServiceProvisioningResource::class;

    public function getTitle(): string
    {
        return 'Provisioning cases';
    }

    public function getSubheading(): ?string
    {
        return 'Track PRV cases, overall provisioning time, and department queues.';
    }
}
