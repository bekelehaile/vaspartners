<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\CompanyStatsOverview;
use App\Filament\Widgets\FeedbackStatsOverview;
use App\Filament\Widgets\PendingCompanyRequestsStats;
use App\Filament\Widgets\RevenueStatsOverview;
use App\Filament\Widgets\SubscriptionStatsOverview;
use App\Filament\Widgets\TicketStatsOverview;
use App\Filament\Widgets\TicketsByStatusChart;
use App\Models\Service;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

    protected static ?string $navigationLabel = 'Dashboard';

    protected static ?int $navigationSort = -10;

    public function persistsFiltersInSession(): bool
    {
        return false;
    }

    public function mount(): void
    {
        $this->filters = array_merge([
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->toDateString(),
            'service_id' => null,
        ], array_filter(
            $this->filters ?? [],
            fn (mixed $value): bool => $value !== null && $value !== '',
        ));

        if (blank($this->filters['start_date'] ?? null)) {
            $this->filters['start_date'] = now()->subMonth()->toDateString();
        }
        if (blank($this->filters['end_date'] ?? null)) {
            $this->filters['end_date'] = now()->toDateString();
        }

        $this->getFiltersForm()->fill($this->filters);
    }

    public function getTitle(): string | \Illuminate\Contracts\Support\Htmlable
    {
        return 'Executive overview';
    }

    public function getColumns(): int | array
    {
        return [
            'default' => 1,
            'md' => 2,
            'xl' => 3,
        ];
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Filters')
                ->description('Default range is the last month. Queue cards stay live; Rejected uses the date range.')
                ->schema([
                    DatePicker::make('start_date')
                        ->label('From')
                        ->native(false)
                        ->displayFormat('Y-m-d')
                        ->default(fn (): string => now()->subMonth()->toDateString())
                        ->suffixIcon(Heroicon::Calendar, isInline: true),
                    DatePicker::make('end_date')
                        ->label('To')
                        ->native(false)
                        ->displayFormat('Y-m-d')
                        ->default(fn (): string => now()->toDateString())
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
                        ->placeholder('All services'),
                ])
                ->columns([
                    'default' => 1,
                    'md' => 3,
                ])
                ->columnSpanFull(),
        ]);
    }

    /**
     * @return array<class-string>
     */
    public function getWidgets(): array
    {
        return [
            CompanyStatsOverview::class,
            RevenueStatsOverview::class,
            TicketStatsOverview::class,
            SubscriptionStatsOverview::class,
            PendingCompanyRequestsStats::class,
            FeedbackStatsOverview::class,
            TicketsByStatusChart::class,
        ];
    }
}
