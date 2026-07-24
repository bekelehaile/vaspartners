<?php

namespace App\Filament\Resources\Companies\RelationManagers;

use App\Enums\TicketStatus;
use App\Filament\Resources\Tickets\TicketResource;
use App\Models\Company;
use App\Models\Ticket;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

class ServiceRequestsRelationManager extends RelationManager
{
    protected static string $relationship = 'serviceRequests';

    protected static ?string $title = 'Service requests';

    protected static ?string $relatedResource = TicketResource::class;

    public function isReadOnly(): bool
    {
        return true;
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        /** @var Company $ownerRecord */
        $count = $ownerRecord->serviceRequests()->count();

        return $count > 0 ? (string) $count : null;
    }

    public function getRelationship(): Relation|Builder
    {
        /** @var Company $company */
        $company = $this->getOwnerRecord();

        return $company->serviceRequests();
    }

    public function table(Table $table): Table
    {
        return $table
            ->description('New subscriptions and manage-service requests for this company (via members, legacy client match, or subscription).')
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with([
                'service',
                'requisition',
                'customer',
                'subscription',
            ]))
            ->columns([
                TextColumn::make('tt_number')->label('Request ID')->searchable()->sortable(),
                TextColumn::make('requisition.name')
                    ->label('Request type')
                    ->badge()
                    ->color(fn (Ticket $record): string => $record->requisition?->creates_subscription
                        ? 'success'
                        : 'info'),
                TextColumn::make('journey')
                    ->label('Journey')
                    ->state(fn (Ticket $record): string => $record->requisition?->creates_subscription
                        ? 'New subscription'
                        : 'Manage service')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'New subscription' ? 'success' : 'info'),
                TextColumn::make('service.name')->label('Service')->searchable()->wrap(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof TicketStatus
                        ? $state->label()
                        : (TicketStatus::tryFrom((string) $state)?->label() ?? (string) $state)),
                TextColumn::make('customer.name')->label('Partner')->toggleable(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                TernaryFilter::make('manage_only')
                    ->label('Manage service only')
                    ->queries(
                        true: fn (Builder $query) => $query->whereHas(
                            'requisition',
                            fn (Builder $q) => $q->where('creates_subscription', false),
                        ),
                        false: fn (Builder $query) => $query->whereHas(
                            'requisition',
                            fn (Builder $q) => $q->where('creates_subscription', true),
                        ),
                        blank: fn (Builder $query) => $query,
                    ),
                SelectFilter::make('status')->options(
                    collect(TicketStatus::cases())->mapWithKeys(
                        fn (TicketStatus $s) => [$s->value => $s->label()]
                    )->all()
                ),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label('Open')
                    ->url(fn (Ticket $record): string => TicketResource::getUrl('view', ['record' => $record])),
            ])
            ->headerActions([])
            ->toolbarActions([]);
    }
}
