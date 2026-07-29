<?php

namespace App\Filament\Widgets;

use App\Enums\TicketStatus;
use App\Filament\Resources\Tickets\TicketResource;
use App\Filament\Widgets\Concerns\AppliesDashboardFilters;
use Filament\Widgets\ChartWidget;

class TicketsByStatusChart extends ChartWidget
{
    use AppliesDashboardFilters;

    protected static ?int $sort = 7;

    protected ?string $heading = 'Tickets by status';

    protected ?string $maxHeight = '280px';

    protected int | string | array $columnSpan = [
        'md' => 2,
        'xl' => 3,
    ];

    protected function getData(): array
    {
        $labels = [];
        $data = [];
        $colors = [];

        foreach (TicketStatus::cases() as $status) {
            $query = TicketResource::getEloquentQuery();
            $this->applyDashboardTicketStatusFilters($query, $status);

            $labels[] = $status->label();
            $data[] = $query->count();
            $colors[] = match ($status) {
                TicketStatus::Open => '#f59e0b',
                TicketStatus::InProgress => '#3b82f6',
                TicketStatus::Rejected => '#ef4444',
                TicketStatus::Completed => '#22c55e',
                TicketStatus::Closed => '#6b7280',
            };
        }

        return [
            'datasets' => [
                [
                    'label' => 'Tickets',
                    'data' => $data,
                    'backgroundColor' => $colors,
                    'borderWidth' => 0,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    public function getHeading(): ?string
    {
        if ($this->hasCustomDateRange()) {
            return 'Tickets by status (event time in range)';
        }

        if (filled($this->dashboardFilters()['service_id'] ?? null)) {
            return 'Tickets by status (live · service filtered)';
        }

        return 'Tickets by status (live)';
    }

    public function getDescription(): ?string
    {
        if (! $this->hasCustomDateRange()) {
            return 'Current queue by status. Set a date range to use opened_at / in_progress_at / completed_at / closed_at / rejected_at per status.';
        }

        return 'Open→opened_at · In progress→in_progress_at · Completed→completed_at · Closed→closed_at · Rejected→rejected_at';
    }
}
