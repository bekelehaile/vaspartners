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
                ->description('Date & service narrow tickets and revenue. Partner / subscription / feedback cards stay current-period snapshots.')
                ->schema([
                    DatePicker::make('start_date')
                        ->label('From')
                        ->native(false)
                        ->displayFormat('Y-m-d')
                        ->placeholder('Any start')
                        ->suffixIcon(Heroicon::Calendar, isInline: true),
                    DatePicker::make('end_date')
                        ->label('To')
                        ->native(false)
                        ->displayFormat('Y-m-d')
                        ->placeholder('Any end')
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
            TicketStatsOverview::class,
            SubscriptionStatsOverview::class,
            PendingCompanyRequestsStats::class,
            FeedbackStatsOverview::class,
            RevenueStatsOverview::class,
            TicketsByStatusChart::class,
        ];
    }
}
