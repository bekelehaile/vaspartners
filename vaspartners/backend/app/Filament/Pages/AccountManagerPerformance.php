<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\AccountManagerPerformanceOverview;
use App\Filament\Resources\Tickets\TicketResource;
use App\Models\Service;
use App\Models\User;
use App\Services\AccountManagerPerformanceService;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
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
use Symfony\Component\HttpFoundation\StreamedResponse;

class AccountManagerPerformance extends Page implements HasSchemas, HasTable
{
    use HasPageShield;
    use InteractsWithSchemas;
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?string $navigationLabel = 'Account handlers';

    protected static ?string $title = 'Account handler performance';

    protected static string|\UnitEnum|null $navigationGroup = 'Reports';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'reports/account-handlers';

    /**
     * Passed to header widgets as pageFilters (see Filament Page::getWidgetsSchemaComponents).
     *
     * @var array{start_date?: ?string, end_date?: ?string, service_id?: int|string|null, user_id?: int|string|null}
     */
    #[Url]
    public array $filters = [];

    /** @var Collection<int, array<string, mixed>>|null */
    protected ?Collection $metricsCache = null;

    public function mount(): void
    {
        $this->filters = [
            'start_date' => $this->filters['start_date'] ?? now()->subMonth()->toDateString(),
            'end_date' => $this->filters['end_date'] ?? now()->toDateString(),
            'service_id' => $this->filters['service_id'] ?? null,
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
        return 'Measure how account handlers clear requests: backlog, throughput, pickup speed, cycle time, and quality.';
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return false;
        }

        if ($user->hasRole('super_admin') || (bool) $user->is_management) {
            return true;
        }

        return parent::canAccess();
    }

    /**
     * @return array<class-string>
     */
    protected function getHeaderWidgets(): array
    {
        return [
            AccountManagerPerformanceOverview::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label('Export CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(function (AccountManagerPerformanceService $service): StreamedResponse {
                    $csv = $service->toCsv($this->reportFilters());
                    $name = 'account-handler-performance-'.now()->format('Ymd-His').'.csv';

                    return response()->streamDownload(
                        function () use ($csv): void {
                            echo $csv;
                        },
                        $name,
                        ['Content-Type' => 'text/csv; charset=UTF-8'],
                    );
                }),
        ];
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Filters')
                    ->description('Default period is the last month. Backlog is live; other metrics use the selected period.')
                    ->schema([
                        DatePicker::make('start_date')
                            ->label('From')
                            ->native(false)
                            ->displayFormat('Y-m-d')
                            ->live()
                            ->suffixIcon(Heroicon::Calendar, isInline: true),
                        DatePicker::make('end_date')
                            ->label('To')
                            ->native(false)
                            ->displayFormat('Y-m-d')
                            ->live()
                            ->suffixIcon(Heroicon::Calendar, isInline: true)
                            ->minDate(fn (callable $get): mixed => $get('start_date') ?: null),
                        Select::make('service_id')
                            ->label('Service')
                            ->options(fn (): array => Service::query()
                                ->orderBy('sort_order')
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->preload()
                            ->placeholder('All services')
                            ->live(),
                        Select::make('user_id')
                            ->label('Account handler')
                            ->options(fn (): array => User::query()
                                ->where('is_active', true)
                                ->where('is_management', false)
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->preload()
                            ->placeholder('All handlers')
                            ->live(),
                    ])
                    ->columns([
                        'default' => 1,
                        'md' => 2,
                        'xl' => 4,
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
            ->heading('Handler leaderboard')
            ->description('Throughput score blends completed volume, backlog pressure, cycle time, and rejection rate (0–100).')
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
                TextColumn::make('throughput_score')
                    ->label('Score')
                    ->badge()
                    ->state(fn (User $record): float => (float) ($this->metric($record)['throughput_score'] ?? 0))
                    ->color(fn (float $state): string => match (true) {
                        $state >= 75 => 'success',
                        $state >= 50 => 'warning',
                        default => 'danger',
                    }),
                TextColumn::make('backlog')
                    ->label('Backlog')
                    ->state(fn (User $record): int => (int) ($this->metric($record)['backlog'] ?? 0))
                    ->color(fn (int $state): string => $state > 0 ? 'warning' : 'gray')
                    ->alignEnd(),
                TextColumn::make('assigned_in_period')
                    ->label('Assigned')
                    ->state(fn (User $record): int => (int) ($this->metric($record)['assigned_in_period'] ?? 0))
                    ->alignEnd()
                    ->toggleable(),
                TextColumn::make('completed')
                    ->label('Completed')
                    ->state(fn (User $record): int => (int) ($this->metric($record)['completed'] ?? 0))
                    ->color('success')
                    ->alignEnd()
                    ->url(fn (User $record): string => TicketResource::getUrl('index').'?tab=completed&'.http_build_query([
                        'tableFilters' => ['assigned_to_user_id' => ['value' => $record->id]],
                    ]))
                    ->tooltip('Completed in selected period — click to open'),
                TextColumn::make('closed')
                    ->label('Closed')
                    ->state(fn (User $record): int => (int) ($this->metric($record)['closed'] ?? 0))
                    ->color('gray')
                    ->alignEnd()
                    ->url(fn (User $record): string => TicketResource::getUrl('index').'?tab=closed&'.http_build_query([
                        'tableFilters' => ['assigned_to_user_id' => ['value' => $record->id]],
                    ]))
                    ->tooltip('Closed in selected period — click to open'),
                TextColumn::make('rejected')
                    ->label('Rejected')
                    ->state(fn (User $record): int => (int) ($this->metric($record)['rejected'] ?? 0))
                    ->color(fn (int $state): string => $state > 0 ? 'danger' : 'gray')
                    ->alignEnd()
                    ->url(fn (User $record): string => TicketResource::getUrl('index').'?tab=rejected&'.http_build_query([
                        'tableFilters' => ['assigned_to_user_id' => ['value' => $record->id]],
                    ]))
                    ->tooltip('Rejected in selected period — click to open')
                    ->toggleable(),
                TextColumn::make('completion_rate')
                    ->label('Complete %')
                    ->state(function (User $record): string {
                        $rate = $this->metric($record)['completion_rate'] ?? null;

                        return $rate === null ? '—' : $rate.'%';
                    })
                    ->alignEnd()
                    ->toggleable(),
                TextColumn::make('avg_cycle_hours')
                    ->label('Cycle (h)')
                    ->state(function (User $record): string {
                        $hours = $this->metric($record)['avg_cycle_hours'] ?? null;

                        return $hours === null ? '—' : (string) $hours;
                    })
                    ->tooltip('Average hours from assign to outcome')
                    ->alignEnd(),
                TextColumn::make('avg_pickup_hours')
                    ->label('Pickup (h)')
                    ->state(function (User $record): string {
                        $hours = $this->metric($record)['avg_pickup_hours'] ?? null;

                        return $hours === null ? '—' : (string) $hours;
                    })
                    ->tooltip('Average hours from create to assign')
                    ->alignEnd()
                    ->toggleable(),
                TextColumn::make('oldest_backlog_hours')
                    ->label('Oldest (h)')
                    ->state(function (User $record): string {
                        $hours = $this->metric($record)['oldest_backlog_hours'] ?? null;

                        return $hours === null ? '—' : (string) $hours;
                    })
                    ->tooltip('Age of oldest open/in-progress request still on this handler')
                    ->color(function (User $record): string {
                        $hours = $this->metric($record)['oldest_backlog_hours'] ?? null;

                        return $hours !== null && $hours > 72 ? 'danger' : 'gray';
                    })
                    ->alignEnd()
                    ->toggleable(),
                TextColumn::make('doc_pass_rate')
                    ->label('Docs pass %')
                    ->state(function (User $record): string {
                        $rate = $this->metric($record)['doc_pass_rate'] ?? null;

                        return $rate === null ? '—' : $rate.'%';
                    })
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultPaginationPageOption(25)
            ->paginated([10, 25, 50, 100])
            ->striped()
            ->emptyStateHeading('No handler activity')
            ->emptyStateDescription('No assigned requests match these filters. Widen the date range or clear the service filter.')
            ->emptyStateIcon(Heroicon::ChartBarSquare);
    }

    /**
     * @return array{start?: ?string, end?: ?string, service_id?: ?int, user_id?: ?int}
     */
    protected function reportFilters(): array
    {
        $filters = $this->filters ?? [];

        return [
            'start' => $filters['start_date'] ?? null,
            'end' => $filters['end_date'] ?? null,
            'service_id' => filled($filters['service_id'] ?? null) ? (int) $filters['service_id'] : null,
            'user_id' => filled($filters['user_id'] ?? null) ? (int) $filters['user_id'] : null,
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    protected function metrics(): Collection
    {
        return $this->metricsCache ??= app(AccountManagerPerformanceService::class)->rows($this->reportFilters());
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
