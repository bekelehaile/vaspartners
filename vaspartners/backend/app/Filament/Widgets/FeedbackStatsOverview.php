<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Feedback\FeedbackResource;
use App\Models\Feedback;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Gate;

class FeedbackStatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 5;

    protected ?string $heading = 'Partner feedback';

    protected ?string $pollingInterval = '120s';

    public static function canView(): bool
    {
        return Gate::allows('ViewAny:Feedback');
    }

    protected function getStats(): array
    {
        $year = Feedback::currentYear();
        $quarter = Feedback::currentQuarter();
        $label = 'Q'.$quarter.' '.$year;

        $base = Feedback::query()
            ->where('year', $year)
            ->where('quarter', $quarter);

        $count = (clone $base)->count();
        $avg = $count > 0
            ? round((float) (clone $base)->avg('rating'), 1)
            : null;
        $low = (clone $base)->where('rating', '<=', 2)->count();

        $avgColor = match (true) {
            $avg === null => 'gray',
            $avg >= 4 => 'success',
            $avg >= 3 => 'warning',
            default => 'danger',
        };

        return [
            Stat::make('Avg rating', $avg !== null ? $avg.'/5' : '—')
                ->description($label.' · '.$count.' responses')
                ->descriptionIcon(Heroicon::OutlinedStar)
                ->color($avgColor)
                ->url(FeedbackResource::getUrl('index')),
            Stat::make('Responses', $count)
                ->description('Submitted this quarter')
                ->descriptionIcon(Heroicon::OutlinedChatBubbleLeftRight)
                ->color($count > 0 ? 'info' : 'gray')
                ->url(FeedbackResource::getUrl('index')),
            Stat::make('Low scores', $low)
                ->description('Rated 1–2 this quarter')
                ->descriptionIcon(Heroicon::OutlinedExclamationTriangle)
                ->color($low > 0 ? 'danger' : 'gray')
                ->url(FeedbackResource::getUrl('index')),
        ];
    }
}
