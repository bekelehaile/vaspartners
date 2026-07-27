<?php

namespace App\Filament\Resources\Tickets\Pages;

use App\Enums\TicketStatus;
use App\Filament\Resources\Tickets\Concerns\SendsFilteredTicketSms;
use App\Filament\Resources\Tickets\TicketResource;
use App\Models\Ticket;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListTickets extends ListRecords
{
    use SendsFilteredTicketSms;

    protected static string $resource = TicketResource::class;

    public function getSubheading(): ?string
    {
        return 'Filter by tab, status, or group before using Send SMS to filtered. Individual and selected-row SMS are always available when permitted.';
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->sendSmsFilteredHeaderAction(),
        ];
    }

    public function getTabs(): array
    {
        $base = fn (): Builder => TicketResource::getEloquentQuery();
        $userId = auth()->id();

        return [
            'all' => Tab::make('All')
                ->badge(fn (): int => $base()->count()),
            'unassigned' => Tab::make('Unassigned')
                ->badge(fn (): int => $base()
                    ->where('status', TicketStatus::Open)
                    ->whereNull('assigned_to_user_id')
                    ->count())
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->where('status', TicketStatus::Open)
                    ->whereNull('assigned_to_user_id')),
            'open' => Tab::make('Pending')
                ->badge(fn (): int => $base()->where('status', TicketStatus::Open)->count())
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', TicketStatus::Open)),
            'in_progress' => Tab::make('In progress')
                ->badge(fn (): int => $base()->where('status', TicketStatus::InProgress)->count())
                ->badgeColor('info')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', TicketStatus::InProgress)),
            'rejected' => Tab::make('Rejected')
                ->badge(fn (): int => $base()->where('status', TicketStatus::Rejected)->count())
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', TicketStatus::Rejected)),
            'approval' => Tab::make('My approval')
                ->badge(fn (): int => Ticket::query()
                    ->where('current_approver_user_id', $userId)
                    ->whereNotIn('status', [TicketStatus::Completed, TicketStatus::Closed])
                    ->count())
                ->badgeColor('primary')
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->where('current_approver_user_id', $userId)
                    ->whereNotIn('status', [TicketStatus::Completed, TicketStatus::Closed])),
            'completed' => Tab::make('Completed')
                ->badge(fn (): int => $base()->where('status', TicketStatus::Completed)->count())
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', TicketStatus::Completed)),
            'closed' => Tab::make('Closed')
                ->badge(fn (): int => $base()->where('status', TicketStatus::Closed)->count())
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', TicketStatus::Closed)),
        ];
    }
}
