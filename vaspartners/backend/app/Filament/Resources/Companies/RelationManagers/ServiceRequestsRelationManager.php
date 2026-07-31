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

class ServiceRequestsRelationManager extends RelationManager
{
    // Placeholder relationship (Filament requires one); table uses ->query() instead.
    protected static string $relationship = 'subscriptions';

    protected static ?string $title = 'Service requests';

    public function isReadOnly(): bool
    {
        return true;
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return true;
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        /** @var Company $ownerRecord */
        $count = $ownerRecord->serviceRequests()->count();

        return $count > 0 ? (string) $count : null;
    }

    public function table(Table $table): Table
    {
        /** @var Company $company */
        $company = $this->getOwnerRecord();

        return $table
            ->query(fn (): Builder => $company->serviceRequests())
            ->relationship(null)
            ->description('Service requests for this company. Request type is separate from subscription status (Active / Deactive).')
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with([
                'service',
                'requisition',
                'contact',
                'subscription',
            ]))
            ->columns([
                TextColumn::make('tt_number')->label('Request number')->searchable()->sortable(),
                TextColumn::make('requisition.name')->label('Request type')->searchable()->wrap(),
                TextColumn::make('service.name')->label('Service')->searchable()->wrap(),
                TextColumn::make('status')
                    ->label('Request status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof TicketStatus
                        ? $state->label()
                        : (TicketStatus::tryFrom((string) $state)?->label() ?? (string) $state))
                    ->color(fn ($state): string => ($state instanceof TicketStatus
                        ? $state
                        : TicketStatus::tryFrom((string) $state)
                    )?->getColor() ?? 'gray'),
                TextColumn::make('subscription.status')
                    ->label('Subscription')
                    ->badge()
                    ->placeholder('—')
                    ->formatStateUsing(fn ($state): string => \App\Enums\SubscriptionStatus::tryLabel($state))
                    ->color(fn ($state): string => \App\Enums\SubscriptionStatus::tryColor($state)),
                TextColumn::make('contact.name')->label('Partner')->toggleable(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                TernaryFilter::make('creates_subscription')
                    ->label('Creates subscription')
                    ->queries(
                        true: fn (Builder $query) => $query->whereHas(
                            'requisition',
                            fn (Builder $q) => $q->where('creates_subscription', true),
                        ),
                        false: fn (Builder $query) => $query->whereHas(
                            'requisition',
                            fn (Builder $q) => $q->where('creates_subscription', false),
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
                    ->label('View')
                    ->url(fn (Ticket $record): string => TicketResource::getUrl('view', ['record' => $record])),
            ])
            ->headerActions([])
            ->toolbarActions([]);
    }
}
