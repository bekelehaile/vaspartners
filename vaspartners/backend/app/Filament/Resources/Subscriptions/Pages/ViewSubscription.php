<?php

namespace App\Filament\Resources\Subscriptions\Pages;

use App\Filament\Resources\Subscriptions\SubscriptionResource;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

class ViewSubscription extends ViewRecord
{
    protected static string $resource = SubscriptionResource::class;

    protected function resolveRecord(int|string $key): \Illuminate\Database\Eloquent\Model
    {
        return parent::resolveRecord($key)->loadMissing(['service', 'company', 'customer']);
    }

    public function getTitle(): string|Htmlable
    {
        return SubscriptionResource::getRecordTitle($this->getRecord()) ?? 'Subscription';
    }

    public function getSubheading(): ?string
    {
        $record = $this->getRecord();
        $bits = array_filter([
            $record->public_id ? 'ID '.$record->public_id : null,
            $record->company?->name,
        ]);

        return $bits !== []
            ? implode(' · ', $bits)
            : 'Details, linked service requests, messages, attachments, and status logs.';
    }
}
