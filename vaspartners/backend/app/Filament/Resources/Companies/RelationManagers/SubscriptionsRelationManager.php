<?php

namespace App\Filament\Resources\Companies\RelationManagers;

use App\Enums\SubscriptionStatus;
use App\Filament\Resources\Companies\CompanyResource;
use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Models\Company;
use App\Models\Subscription;
use App\Services\CompanyChildAttachService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class SubscriptionsRelationManager extends RelationManager
{
    protected static string $relationship = 'subscriptions';

    protected static ?string $title = 'Subscriptions';

    public function isReadOnly(): bool
    {
        return true;
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = $ownerRecord->subscriptions()->count();

        return $count > 0 ? (string) $count : null;
    }

    public function table(Table $table): Table
    {
        /** @var Company $company */
        $company = $this->getOwnerRecord();

        return $table
            ->description('Subscriptions belong to this company and stay with it when ownership transfers.')
            ->modifyQueryUsing(fn ($query) => $query->with(['service', 'contact']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('service.name')->label('Service')->searchable()->wrap(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => SubscriptionStatus::tryLabel($state))
                    ->color(fn ($state): string => SubscriptionStatus::tryColor($state)),
                TextColumn::make('contact.name')->label('Activated by')->toggleable(),
                TextColumn::make('current_period_end')->dateTime()->sortable()->toggleable(),
                TextColumn::make('next_renewal_due_at')->dateTime()->toggleable(),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label('View')
                    ->url(fn (Subscription $record): string => SubscriptionResource::getUrl('view', ['record' => $record])),
            ])
            ->headerActions([
                Action::make('attach_subscription')
                    ->label('Attach subscription')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->color('primary')
                    ->visible(fn (): bool => CompanyResource::canEdit($this->getOwnerRecord()))
                    ->modalHeading('Attach subscription to '.$company->name)
                    ->modalDescription('Search for a subscription that is not yet linked to this company and reassign it. The subscription stays with the company it is assigned to.')
                    ->modalSubmitActionLabel('Attach')
                    ->form([
                        \Filament\Forms\Components\Select::make('subscription_id')
                            ->label('Subscription')
                            ->searchable()
                            ->getSearchResultsUsing(function (string $search) use ($company): array {
                                return Subscription::query()
                                    ->where('company_id', '!=', $company->id)
                                    ->whereNull('subscriptions.deleted_at')
                                    ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                                        ->whereHas('service', fn (Builder $s) => $s->where('name', 'like', '%'.$search.'%'))
                                        ->orWhereHas('contact', fn (Builder $c) => $c->where('name', 'like', '%'.$search.'%'))
                                        ->orWhere('id', $search)))
                                    ->limit(50)
                                    ->get()
                                    ->mapWithKeys(fn (Subscription $s) => [$s->id => '#'.$s->id.' — '.($s->service?->name ?? '—').' / '.($s->contact?->name ?? '—')])
                                    ->all();
                            })
                            ->options(fn () => [])
                            ->required(),
                    ])
                    ->action(function (array $data, CompanyChildAttachService $service) use ($company): void {
                        $subscription = Subscription::query()->find((int) ($data['subscription_id'] ?? 0));

                        if (! $subscription) {
                            \Filament\Notifications\Notification::make()
                                ->title('Subscription not found')
                                ->danger()
                                ->send();

                            return;
                        }

                        try {
                            $service->attachSubscription($subscription, $company, auth()->user());

                            \Filament\Notifications\Notification::make()
                                ->title('Subscription attached')
                                ->body('Subscription #'.$subscription->id.' is now linked to '.$company->name.'.')
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            \Filament\Notifications\Notification::make()
                                ->title('Could not attach subscription')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->toolbarActions([]);
    }
}