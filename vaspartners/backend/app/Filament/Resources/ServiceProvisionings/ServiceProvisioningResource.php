<?php

namespace App\Filament\Resources\ServiceProvisionings;

use App\Enums\ProvisioningDepartment;
use App\Enums\ProvisioningStatus;
use App\Enums\ProvisioningStepStatus;
use App\Filament\Resources\ServiceProvisionings\Pages\ListServiceProvisionings;
use App\Filament\Resources\ServiceProvisionings\Pages\ViewServiceProvisioning;
use App\Filament\Resources\ServiceProvisionings\RelationManagers\AttachmentsRelationManager;
use App\Filament\Resources\ServiceProvisionings\RelationManagers\MessagesRelationManager;
use App\Filament\Resources\ServiceProvisionings\RelationManagers\StepsRelationManager;
use App\Filament\Resources\ServiceProvisionings\Support\ProvisioningStepActions;
use App\Models\ServiceProvisioning;
use App\Models\User;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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
        $query = parent::getEloquentQuery()->with(['company', 'service', 'subscription', 'steps']);

        /** @var User|null $user */
        $user = auth()->user();
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        // Bypass roles (super_admin, admin, supervisor, management): unscoped view access.
        if ($user->canBypassServiceProvisioningScope()) {
            return $query;
        }

        $deptRoles = $user->provisioningDepartments();
        $isAm = $user->hasRole(User::ROLE_ACCOUNT_MANAGER);

        // No department attachment and not AM → nothing in the list.
        if ($deptRoles === [] && ! $isAm) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $q) use ($user, $deptRoles, $isAm): void {
            // Third-party / section users: cases for departments attached at user creation.
            if ($deptRoles !== []) {
                $q->where(function (Builder $inner) use ($deptRoles): void {
                    $inner->whereHas('steps', function (Builder $steps) use ($deptRoles): void {
                        $steps->whereIn('from_department', $deptRoles);
                    });
                });
            }

            // Account managers: only cases on subscriptions assigned to them.
            if ($isAm) {
                $method = $deptRoles !== [] ? 'orWhereHas' : 'whereHas';
                $q->{$method}('subscription.tickets', function (Builder $tickets) use ($user): void {
                    $tickets->where(function (Builder $t) use ($user): void {
                        $t->where('assigned_to_user_id', $user->id)
                            ->orWhere('current_approver_user_id', $user->id);
                    });
                });
            }
        });
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
            Section::make('Shared credentials')
                ->description('Shown when credentials have been released to the partner.')
                ->visible(fn (ServiceProvisioning $record): bool => (bool) ($record->shared_payload['credentials_released_to_partner'] ?? false)
                    && filled($record->shared_payload['credentials'] ?? null))
                ->schema([
                    KeyValueEntry::make('credentials')
                        ->label('')
                        ->state(fn (ServiceProvisioning $record): array => (array) ($record->shared_payload['credentials'] ?? [])),
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
                TextColumn::make('waiting_on')
                    ->label('Waiting on')
                    ->state(function (ServiceProvisioning $record): string {
                        $labels = $record->steps
                            ->filter(fn ($s) => $s->status === ProvisioningStepStatus::Waiting)
                            ->map(function ($s): string {
                                $dept = $s->from_department instanceof ProvisioningDepartment
                                    ? $s->from_department->label()
                                    : (string) $s->from_department;

                                return $dept !== '' ? $dept : '—';
                            })
                            ->unique()
                            ->values();

                        return $labels->isEmpty() ? '—' : $labels->implode(', ');
                    })
                    ->wrap()
                    ->toggleable(),
                TextColumn::make('current_step_key')->label('Current step')->toggleable(isToggledHiddenByDefault: true),
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
                SelectFilter::make('waiting_department')
                    ->label('Waiting department')
                    ->options(
                        collect(ProvisioningDepartment::cases())
                            ->reject(fn (ProvisioningDepartment $d) => $d === ProvisioningDepartment::Partner)
                            ->mapWithKeys(fn (ProvisioningDepartment $d) => [$d->value => $d->label()])
                            ->all()
                    )
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;
                        if (! filled($value)) {
                            return $query;
                        }

                        return $query->whereHas('steps', function (Builder $steps) use ($value): void {
                            $steps->where('status', ProvisioningStepStatus::Waiting->value)
                                ->where('from_department', $value);
                        });
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
                ActionGroup::make(ProvisioningStepActions::forTable())
                    ->label('Section actions')
                    ->icon('heroicon-o-play')
                    ->color('primary')
                    ->button()
                    ->visible(fn (ServiceProvisioning $record): bool => ProvisioningStepActions::actionableSteps($record)->isNotEmpty()),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            StepsRelationManager::class,
            MessagesRelationManager::class,
            AttachmentsRelationManager::class,
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
