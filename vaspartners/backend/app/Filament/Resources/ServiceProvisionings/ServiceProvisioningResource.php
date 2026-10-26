<?php

namespace App\Filament\Resources\ServiceProvisionings;

use App\Enums\ProvisioningStatus;
use App\Filament\Resources\ServiceProvisionings\Pages\ListServiceProvisionings;
use App\Filament\Resources\ServiceProvisionings\Pages\ViewServiceProvisioning;
use App\Filament\Resources\ServiceProvisionings\RelationManagers\MessagesRelationManager;
use App\Models\ServiceProvisioning;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ServiceProvisioningResource extends Resource
{
    protected static ?string $model = ServiceProvisioning::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedRocketLaunch;

    protected static string|\UnitEnum|null $navigationGroup = 'Services';

    protected static ?string $navigationLabel = 'Service activation';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'Service activation';

    protected static ?string $pluralModelLabel = 'Service activations';

    protected static ?string $slug = 'service-activations';

    protected static ?string $recordTitleAttribute = 'public_id';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['company', 'service', 'subscription', 'steps']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Case')->schema([
                TextEntry::make('public_id')->label('PRV number'),
                TextEntry::make('status')->badge(),
                TextEntry::make('workflow_key')->label('Workflow')->badge(),
                TextEntry::make('company.name')->label('Company'),
                TextEntry::make('service.name')->label('Service'),
                TextEntry::make('subscription.public_id')
                    ->label('Subscription')
                    ->url(fn (ServiceProvisioning $record): ?string => $record->subscription
                        ? \App\Filament\Resources\Subscriptions\SubscriptionResource::getUrl('view', ['record' => $record->subscription])
                        : null),
                TextEntry::make('current_step_key')->label('Current step'),
                TextEntry::make('overall_days')
                    ->label('Overall provisioning time')
                    ->state(fn (ServiceProvisioning $record): string => $record->overallElapsedDays().' day(s)'),
                TextEntry::make('started_at')->dateTime(),
                TextEntry::make('completed_at')->dateTime()->placeholder('—'),
            ])->columns(3),
            Section::make('Follow-up tracking')
                ->description('Collapsed by default — expand to review handoffs.')
                ->collapsed()
                ->schema([
                    RepeatableEntry::make('steps')
                        ->label('')
                        ->schema([
                            TextEntry::make('label')->label('What'),
                            TextEntry::make('where')
                                ->label('Where')
                                ->state(fn (Model $record): string => $record instanceof \App\Models\ServiceProvisioningStep
                                    ? $record->whereLabel()
                                    : '—'),
                            TextEntry::make('status')->badge(),
                            TextEntry::make('created_at')->label('Created at')->dateTime(),
                            TextEntry::make('days')
                                ->label('Days in step')
                                ->state(fn (Model $record): string => $record instanceof \App\Models\ServiceProvisioningStep
                                    ? (string) $record->daysInStep()
                                    : '—'),
                        ])
                        ->columns(5),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('public_id')->label('PRV')->searchable()->sortable(),
                TextColumn::make('company.name')->label('Company')->searchable()->wrap(),
                TextColumn::make('service.name')->label('Service')->wrap(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('current_step_key')->label('Current step')->toggleable(),
                TextColumn::make('overall_days')
                    ->label('Overall days')
                    ->state(fn (ServiceProvisioning $record): int => $record->overallElapsedDays())
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query->orderByRaw('COALESCE(completed_at, NOW()) - COALESCE(started_at, created_at) '.$direction);
                    }),
                TextColumn::make('started_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(collect(ProvisioningStatus::cases())->mapWithKeys(
                    fn (ProvisioningStatus $s) => [$s->value => $s->label()]
                )),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            MessagesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListServiceProvisionings::route('/'),
            'view' => ViewServiceProvisioning::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
