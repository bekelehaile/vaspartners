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
        $query = $this->applyDashboardTicketFilters(TicketResource::getEloquentQuery());

        $labels = [];
        $data = [];
        $colors = [];

        foreach (TicketStatus::cases() as $status) {
            $labels[] = $status->label();
            $data[] = (clone $query)->where('status', $status)->count();
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
        $parts = ['Tickets by status'];
        $filters = $this->dashboardFilters();

        if (filled($filters['service_id'] ?? null) || $this->hasCustomDateRange()) {
            $parts[] = '(filtered)';
        }

        return implode(' ', $parts);
    }
}
