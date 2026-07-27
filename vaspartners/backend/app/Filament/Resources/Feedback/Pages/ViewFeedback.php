<?php

namespace App\Filament\Resources\Feedback\Pages;

use App\Filament\Resources\Feedback\FeedbackResource;
use App\Models\Feedback;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;

class ViewFeedback extends ViewRecord
{
    protected static string $resource = FeedbackResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->loadMissing(['contact', 'company']);
    }

    public function getTitle(): string|Htmlable
    {
        /** @var Feedback $record */
        $record = $this->getRecord();

        return 'Feedback '.$record->quarterLabel();
    }

    public function getSubheading(): ?string
    {
        /** @var Feedback $record */
        $record = $this->getRecord();

        return trim(($record->contact?->name ?? 'Partner').' · '.$record->rating.'/5');
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
