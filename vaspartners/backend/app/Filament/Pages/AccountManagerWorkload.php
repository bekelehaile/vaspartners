<?php

namespace App\Filament\Pages;

use App\Enums\TicketStatus;
use App\Filament\Resources\Tickets\TicketResource;
use App\Filament\Widgets\AccountManagerWorkloadOverview;
use App\Models\Service;
use App\Models\User;
use App\Services\AccountManagerWorkloadService;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

class AccountManagerWorkload extends Page implements HasSchemas, HasTable
{
    use HasPageShield;
    use InteractsWithSchemas;
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-queue-list';

    protected static ?string $navigationLabel = 'Handler workload';

    protected static ?string $title = 'Account handler workload';

    protected static string|\UnitEnum|null $navigationGroup = 'Reports';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'reports/handler-workload';

    /**
     * @var array{service_ids?: array<int|string>|null, user_id?: int|string|null}
     */
    #[Url]
    public array $filters = [];

    /** @var Collection<int, array<string, mixed>>|null */
    protected ?Collection $metricsCache = null;

    public function mount(): void
    {
        $this->filters = [
            'service_ids' => $this->filters['service_ids'] ?? [],
            'user_id' => $this->filters['user_id'] ?? null,
        ];

        $this->filtersForm->fill($this->filters);
    }

    public function updatedFilters(): void
    {
        $this->metricsCache = null;
        unset($this->cachedHeaderWidgetsSchemaComponents);
        $this->resetTable();
    }

    public function getSubheading(): ?string
    {
        return 'Live count of tickets each account handler currently holds, by real ticket status.';
    }

    /**
     * @return array<class-string>
     */
    protected function getHeaderWidgets(): array
    {
        return [
            AccountManagerWorkloadOverview::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Filters')
                    ->description('Live snapshot — not period-bound. Counts use current ticket status.')
                    ->schema([
                        Select::make('service_ids')
                            ->label('Services')
                            ->multiple()
                            ->native(false)
                            ->options(fn (): array => Service::query()
                                ->where('is_active', true)
                                ->orderBy('sort_order')
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->mapWithKeys(fn ($name, $id) => [(string) $id => $name])
                                ->all())
                            ->searchable()
                            ->preload()
                            ->placeholder('All services')
                            ->live(),
                        Select::make('user_id')
                            ->label('Account handler')
                            ->options(fn (): array => User::assignableManagersForCategory(null)->all())
                            ->searchable()
                            ->preload()
                            ->placeholder('All handlers')
                            ->live(),
                    ])
                    ->columns([
                        'default' => 1,
                        'md' => 2,
                    ])
                    ->columnSpanFull(),
            ])
            ->statePath('filters')
            ->live();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('filtersForm')])
                ->id('filtersForm')
                ->livewireSubmitHandler('noopFilters'),
            EmbeddedTable::make(),
        ]);
    }

    public function noopFilters(): void
    {
        // Filters are live; no submit required.
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Tickets held by handler')
            ->description('Sorted by holding (Pending + In progress). Click a status count to open matching tickets.')
            ->query($this->managersQuery())
            ->columns([
                TextColumn::make('rank')
                    ->label('#')
                    ->state(function (User $record): int {
                        $index = $this->metrics()
                            ->search(fn (array $row): bool => $row['user_id'] === (int) $record->id);

                        return $index === false ? 0 : $index + 1;
                    })
                    ->alignCenter(),
                TextColumn::make('name')
                    ->label('Account handler')
                    ->searchable()
                    ->description(fn (User $record): string => (string) ($record->email ?: '')),
                TextColumn::make('holding')
                    ->label('Holding')
                    ->state(fn (User $record): int => (int) ($this->metric($record)['holding'] ?? 0))
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'warning' : 'success')
                    ->alignEnd()
                    ->tooltip('Pending + In progress'),
                TextColumn::make('open')
                    ->label(TicketStatus::Open->label())
                    ->state(fn (User $record): int => (int) ($this->metric($record)['open'] ?? 0))
                    ->color(fn (int $state): string => $state > 0 ? TicketStatus::Open->getColor() : 'gray')
                    ->alignEnd()
                    ->url(fn (User $record): string => $this->ticketsUrl('open', $record->id)),
                TextColumn::make('in_progress')
                    ->label(TicketStatus::InProgress->label())
                    ->state(fn (User $record): int => (int) ($this->metric($record)['in_progress'] ?? 0))
                    ->color(fn (int $state): string => $state > 0 ? TicketStatus::InProgress->getColor() : 'gray')
                    ->alignEnd()
                    ->url(fn (User $record): string => $this->ticketsUrl('in_progress', $record->id)),
                TextColumn::make('rejected')
                    ->label(TicketStatus::Rejected->label())
                    ->state(fn (User $record): int => (int) ($this->metric($record)['rejected'] ?? 0))
                    ->color(fn (int $state): string => $state > 0 ? TicketStatus::Rejected->getColor() : 'gray')
                    ->alignEnd()
                    ->url(fn (User $record): string => $this->ticketsUrl('rejected', $record->id)),
                TextColumn::make('completed')
                    ->label(TicketStatus::Completed->label())
                    ->state(fn (User $record): int => (int) ($this->metric($record)['completed'] ?? 0))
                    ->color(fn (int $state): string => $state > 0 ? TicketStatus::Completed->getColor() : 'gray')
                    ->alignEnd()
                    ->url(fn (User $record): string => $this->ticketsUrl('completed', $record->id)),
                TextColumn::make('closed')
                    ->label(TicketStatus::Closed->label())
                    ->state(fn (User $record): int => (int) ($this->metric($record)['closed'] ?? 0))
                    ->color('gray')
                    ->alignEnd()
                    ->url(fn (User $record): string => $this->ticketsUrl('closed', $record->id)),
                TextColumn::make('total')
                    ->label('Total')
                    ->state(fn (User $record): int => (int) ($this->metric($record)['total'] ?? 0))
                    ->weight('bold')
                    ->alignEnd()
                    ->url(fn (User $record): string => $this->ticketsUrl('all', $record->id)),
            ])
            ->defaultPaginationPageOption(25)
            ->paginated([10, 25, 50, 100])
            ->striped()
            ->emptyStateHeading('No assigned tickets')
            ->emptyStateDescription('No account handlers currently hold tickets matching these filters.')
            ->emptyStateIcon(Heroicon::QueueList);
    }

    protected function ticketsUrl(string $tab, int $handlerId): string
    {
        $url = TicketResource::getUrl('index').'?tab='.urlencode($tab);
        $tableFilters = [
            'assigned_to_user_id' => ['value' => $handlerId],
        ];

        $serviceIds = collect(\Illuminate\Support\Arr::wrap($this->filters['service_ids'] ?? []))
            ->filter(fn ($id) => filled($id))
            ->map(fn ($id) => (string) (int) $id)
            ->filter(fn (string $id) => $id !== '0')
            ->values()
            ->all();

        if ($serviceIds !== []) {
            $tableFilters['service_id'] = ['values' => $serviceIds];
        }

        return $url.'&'.http_build_query(['tableFilters' => $tableFilters]);
    }

    /**
     * @return array{service_ids?: list<int>, user_id?: ?int}
     */
    protected function reportFilters(): array
    {
        $filters = $this->filters ?? [];
        $serviceIds = collect(\Illuminate\Support\Arr::wrap($filters['service_ids'] ?? []))
            ->filter(fn ($id) => filled($id))
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        return [
            'service_ids' => $serviceIds,
            'user_id' => filled($filters['user_id'] ?? null) ? (int) $filters['user_id'] : null,
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    protected function metrics(): Collection
    {
        return $this->metricsCache ??= app(AccountManagerWorkloadService::class)->rows($this->reportFilters());
    }

    /** @return array<string, mixed> */
    protected function metric(User $record): array
    {
        return $this->metrics()->firstWhere('user_id', (int) $record->id) ?? [];
    }

    protected function managersQuery(): Builder
    {
        $ids = $this->metrics()->pluck('user_id')->map(fn ($id) => (int) $id)->all();

        if ($ids === []) {
            return User::query()->whereRaw('0 = 1');
        }

        $order = implode(',', $ids);

        return User::query()
            ->whereIn('id', $ids)
            ->orderByRaw("array_position(ARRAY[{$order}]::bigint[], id)");
    }
}
