<?php

namespace App\Filament\Resources\Tickets;

use App\Enums\ApprovalAction;
use App\Enums\DocumentReviewStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\TicketStatus;
use App\Filament\Resources\Companies\CompanyResource;
use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Filament\Resources\Tickets\Pages\ListTickets;
use App\Filament\Resources\Tickets\Pages\ViewTicket;
use App\Filament\Resources\Tickets\RelationManagers\ApprovalStepsRelationManager;
use App\Filament\Resources\Tickets\RelationManagers\DocumentReviewsRelationManager;
use App\Filament\Resources\Tickets\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\Tickets\RelationManagers\MessagesRelationManager;
use App\Filament\Resources\Tickets\RelationManagers\StatusHistoryRelationManager;
use App\Models\Priority;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\User;
use App\Services\SmsService;
use App\Services\TicketWorkflowService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Support\Enums\FontWeight;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Throwable;

class TicketResource extends Resource
{
    protected static ?string $model = Ticket::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-ticket';

    protected static string|\UnitEnum|null $navigationGroup = 'Tickets';

    protected static ?string $navigationLabel = 'All tickets';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'tt_number';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Request')->schema([
                TextEntry::make('tt_number')->label('Request number'),
                TextEntry::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof TicketStatus
                        ? $state->label()
                        : (TicketStatus::tryFrom((string) $state)?->label() ?? (string) $state))
                    ->color(fn ($state): string => ($state instanceof TicketStatus
                        ? $state
                        : TicketStatus::tryFrom((string) $state)
                    )?->getColor() ?? 'gray'),
                TextEntry::make('service.name')->label('Service')->placeholder('—'),
                TextEntry::make('requisition.name')->label('Request type')->placeholder('—'),
                TextEntry::make('category.name')->label('Group')->placeholder('—'),
                TextEntry::make('priority.name')->label('Priority')->placeholder('—'),
                TextEntry::make('description')
                    ->label('Description')
                    ->placeholder('—')
                    ->columnSpanFull(),
            ])->columns(2),

            Section::make('Documents')->schema([
                TextEntry::make('attachments_badge')
                    ->label('Attachments')
                    ->badge()
                    ->state(fn (Ticket $record): string => $record->attachmentStatus()['label'])
                    ->color(fn (Ticket $record): string => match ($record->attachmentStatus()['state']) {
                        'complete' => 'success',
                        'incomplete' => 'danger',
                        default => 'gray',
                    }),
                TextEntry::make('document_review_status')
                    ->label('Doc review')
                    ->badge()
                    ->placeholder('—')
                    ->formatStateUsing(fn (Ticket $record): string => $record->documentReviewLabel())
                    ->color(fn (Ticket $record): string => $record->documentReviewColor()),
                TextEntry::make('missing_attachments')
                    ->label('Missing required docs')
                    ->state(function (Ticket $record): string {
                        $status = $record->attachmentStatus();
                        if ($status['state'] === 'none_required') {
                            return 'None — this request type has no required documents';
                        }

                        $names = $status['missing_names'] ?? [];

                        return $names === [] ? 'None — all required docs on file' : implode(', ', $names);
                    })
                    ->color(fn (Ticket $record): string => ($record->attachmentStatus()['missing_count'] ?? 0) > 0
                        ? 'danger'
                        : 'success')
                    ->columnSpanFull(),
            ])->columns(2),

            Section::make('Partner & company')->schema([
                TextEntry::make('contact.name')
                    ->label('Contact')
                    ->placeholder('—')
                    ->icon(fn (Ticket $record): ?string => $record->contact
                        ? 'heroicon-m-arrow-top-right-on-square'
                        : null)
                    ->iconColor('primary')
                    ->color(fn (Ticket $record): ?string => $record->contact ? 'primary' : null)
                    ->weight(fn (Ticket $record): ?FontWeight => $record->contact
                        ? FontWeight::SemiBold
                        : null)
                    ->url(fn (Ticket $record): ?string => $record->contact
                        ? ContactResource::getUrl('view', ['record' => $record->contact])
                        : null)
                    ->extraAttributes(fn (Ticket $record): array => $record->contact
                        ? ['class' => '[&_a]:underline [&_a]:underline-offset-2']
                        : []),
                TextEntry::make('contact.phone_number')->label('Phone')->placeholder('—'),
                TextEntry::make('company_name')
                    ->label('Company')
                    ->placeholder('—')
                    ->state(function (Ticket $record): ?string {
                        return $record->subscription?->company?->name
                            ?? $record->contact?->company?->name
                            ?? $record->contact?->company_name;
                    })
                    ->icon(function (Ticket $record): ?string {
                        $company = $record->subscription?->company ?? $record->contact?->company;

                        return $company ? 'heroicon-m-arrow-top-right-on-square' : null;
                    })
                    ->iconColor('primary')
                    ->color(function (Ticket $record): ?string {
                        $company = $record->subscription?->company ?? $record->contact?->company;

                        return $company ? 'primary' : null;
                    })
                    ->weight(function (Ticket $record): ?FontWeight {
                        $company = $record->subscription?->company ?? $record->contact?->company;

                        return $company ? FontWeight::SemiBold : null;
                    })
                    ->url(function (Ticket $record): ?string {
                        $company = $record->subscription?->company ?? $record->contact?->company;

                        return $company ? CompanyResource::getUrl('view', ['record' => $company]) : null;
                    })
                    ->extraAttributes(function (Ticket $record): array {
                        $company = $record->subscription?->company ?? $record->contact?->company;

                        return $company
                            ? ['class' => '[&_a]:underline [&_a]:underline-offset-2']
                            : [];
                    }),
                TextEntry::make('company_tin')
                    ->label('TIN number')
                    ->placeholder('—')
                    ->state(fn (Ticket $record): ?string => $record->subscription?->company?->tin
                        ?? $record->contact?->company?->tin
                        ?? $record->contact?->company_tin),
            ])->columns(2),

            Section::make('Assignment & timeline')->schema([
                TextEntry::make('assignee.name')->label('Account manager')->placeholder('—'),
                TextEntry::make('currentApprover.name')->label('Current approver')->placeholder('—'),
                TextEntry::make('audit_trail')
                    ->label('Status audit (who · when)')
                    ->columnSpanFull()
                    ->html()
                    ->state(function (Ticket $record): string {
                        $entries = app(\App\Services\TicketAuditTrailService::class)->entries($record);
                        if ($entries === []) {
                            return '—';
                        }

                        $lines = collect($entries)->map(function (array $e): string {
                            $when = ! empty($e['at'])
                                ? \Illuminate\Support\Carbon::parse($e['at'])->timezone(config('app.timezone'))->format('Y-m-d H:i')
                                : '—';
                            $who = e((string) ($e['actor_name'] ?? 'System'));
                            $label = e((string) ($e['label'] ?? 'Status'));
                            $detail = ! empty($e['detail']) ? ' · '.e((string) $e['detail']) : '';

                            return "<div><strong>{$label}</strong> — {$who}{$detail} · <span class=\"text-gray-500\">{$when}</span></div>";
                        })->implode('');

                        return '<div class="space-y-1 text-sm leading-relaxed">'.$lines.'</div>';
                    }),
                TextEntry::make('created_at')->label('Submitted')->dateTime()->placeholder('—'),
                TextEntry::make('opened_at')->label('Pending at')->dateTime()->placeholder('—'),
                TextEntry::make('assigned_at')->label('Assigned at')->dateTime()->placeholder('—'),
                TextEntry::make('in_progress_at')->label('In progress at')->dateTime()->placeholder('—'),
                TextEntry::make('completed_at')->label('Completed / approved at')->dateTime()->placeholder('—'),
                TextEntry::make('closed_at')->label('Closed at')->dateTime()->placeholder('—'),
                TextEntry::make('rejected_at')->label('Rejected at')->dateTime()->placeholder('—'),
                TextEntry::make('escalated_at')->label('Escalated at')->dateTime()->placeholder('—'),
            ])->columns(2),

            Section::make('Related')->schema([
                TextEntry::make('subscription.service.name')
                    ->label('Subscription')
                    ->placeholder('—')
                    ->url(fn (Ticket $record): ?string => $record->subscription
                        ? SubscriptionResource::getUrl('view', ['record' => $record->subscription])
                        : null),
                TextEntry::make('subscription.status')
                    ->label('Subscription status')
                    ->badge()
                    ->placeholder('—')
                    ->formatStateUsing(fn ($state): string => SubscriptionStatus::tryLabel($state))
                    ->color(fn ($state): string => SubscriptionStatus::tryColor($state)),
                TextEntry::make('parentTicket.tt_number')
                    ->label('Parent request')
                    ->placeholder('—')
                    ->url(fn (Ticket $record): ?string => $record->parentTicket
                        ? static::getUrl('view', ['record' => $record->parentTicket])
                        : null),
                TextEntry::make('building')->label('Building')->placeholder('—'),
                TextEntry::make('location')->label('Location')->placeholder('—'),
            ])->columns(2),
        ]);
    }

    public static function getRelations(): array
    {
        return [
            MessagesRelationManager::class,
            DocumentsRelationManager::class,
            ApprovalStepsRelationManager::class,
            DocumentReviewsRelationManager::class,
            StatusHistoryRelationManager::class,
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with([
                'subscription.company',
                'subscription',
                'requisition',
                'contact.company',
                'service',
            ]))
            ->columns([
                TextColumn::make('tt_number')->label('Request number')->searchable()->sortable(),
                TextColumn::make('company_name')
                    ->label('Company')
                    ->placeholder('—')
                    ->toggleable()
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        $like = '%'.$search.'%';

                        return $query->where(function (Builder $q) use ($like): void {
                            $q->whereHas('subscription.company', fn (Builder $c) => $c->where('name', 'like', $like))
                                ->orWhereHas('contact.company', fn (Builder $c) => $c->where('name', 'like', $like))
                                ->orWhereHas('contact', fn (Builder $c) => $c->where('company_name', 'like', $like));
                        });
                    })
                    ->state(fn (Ticket $record): ?string => $record->subscription?->company?->name
                        ?? $record->contact?->company?->name
                        ?? $record->contact?->company_name),
                TextColumn::make('contact.name')->label('Contact')->toggleable(),
                TextColumn::make('contact.phone_number')->label('Phone')->toggleable(),
                TextColumn::make('service.name')->sortable(),
                TextColumn::make('subscription.status')
                    ->label('Subscription')
                    ->badge()
                    ->placeholder('—')
                    ->toggleable()
                    ->state(function (Ticket $record): ?string {
                        if ($record->subscription?->status) {
                            $status = $record->subscription->status;

                            return $status instanceof SubscriptionStatus
                                ? $status->value
                                : (string) $status;
                        }

                        // New-subscription requests have no row until approved.
                        if ($record->requisition?->creates_subscription) {
                            return 'pending_create';
                        }

                        return null;
                    })
                    ->formatStateUsing(function (?string $state): string {
                        if ($state === 'pending_create') {
                            return 'Not created yet';
                        }

                        return $state ? SubscriptionStatus::tryLabel($state) : '—';
                    })
                    ->color(function (?string $state): string {
                        if ($state === 'pending_create') {
                            return 'gray';
                        }

                        return $state ? SubscriptionStatus::tryColor($state) : 'gray';
                    })
                    ->url(fn (Ticket $record): ?string => $record->subscription
                        ? SubscriptionResource::getUrl('view', ['record' => $record->subscription])
                        : null),
                TextColumn::make('category.name')->label('Group')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('requisition.name')->label('Request type')->toggleable(),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof TicketStatus
                        ? $state->label()
                        : (TicketStatus::tryFrom((string) $state)?->label() ?? (string) $state))
                    ->color(fn ($state): string => ($state instanceof TicketStatus
                        ? $state
                        : TicketStatus::tryFrom((string) $state)
                    )?->getColor() ?? 'gray'),
                TextColumn::make('attachments')
                    ->label('Attachments')
                    ->badge()
                    ->state(fn (Ticket $record): string => $record->attachmentStatus()['label'])
                    ->color(fn (Ticket $record): string => match ($record->attachmentStatus()['state']) {
                        'complete' => 'success',
                        'incomplete' => 'danger',
                        default => 'gray',
                    })
                    ->tooltip(function (Ticket $record): ?string {
                        $status = $record->attachmentStatus();
                        if ($status['state'] !== 'incomplete') {
                            return null;
                        }

                        return 'Missing: '.implode(', ', $status['missing_names']);
                    }),
                TextColumn::make('document_review_status')
                    ->label('Doc review')
                    ->badge()
                    ->toggleable()
                    ->formatStateUsing(fn (Ticket $record): string => $record->documentReviewLabel())
                    ->color(fn (Ticket $record): string => $record->documentReviewColor()),
                TextColumn::make('assignee.name')->label('AM'),
                TextColumn::make('currentApprover.name')->label('Approver'),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(collect(TicketStatus::cases())->mapWithKeys(
                    fn (TicketStatus $s) => [$s->value => $s->label()]
                )),
                SelectFilter::make('service_id')
                    ->label('Services')
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->options(fn (): array => Service::query()
                        ->where('is_active', true)
                        ->orderBy('sort_order')
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->mapWithKeys(fn ($name, $id) => [(string) $id => $name])
                        ->all())
                    ->query(function (Builder $query, array $data): Builder {
                        $serviceIds = collect(Arr::wrap($data['values'] ?? []))
                            ->filter(fn ($id) => filled($id))
                            ->map(fn ($id) => (int) $id)
                            ->unique()
                            ->values()
                            ->all();

                        if ($serviceIds === []) {
                            return $query;
                        }

                        return $query->whereIn('service_id', $serviceIds);
                    }),
                SelectFilter::make('subscription_status')
                    ->label('Subscription status')
                    ->options(SubscriptionStatus::options())
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;
                        if (! filled($value)) {
                            return $query;
                        }

                        return $query->whereHas(
                            'subscription',
                            fn (Builder $q) => $q->where('status', $value),
                        );
                    }),
                SelectFilter::make('assigned_to_user_id')
                    ->label('Account handler')
                    ->options(fn (): array => User::assignableManagersForCategory(null)->all())
                    ->searchable()
                    ->preload(),
                SelectFilter::make('category_id')
                    ->label('Group')
                    ->relationship(
                        name: 'category',
                        titleAttribute: 'name',
                        modifyQueryUsing: fn (Builder $query) => $query
                            ->whereIn('key', [\App\Models\Category::KEY_GROUP_1, \App\Models\Category::KEY_GROUP_2])
                            ->orderBy('sort_order'),
                    ),
                SelectFilter::make('attachments')
                    ->label('Attachments')
                    ->options([
                        'incomplete' => 'Missing required docs',
                        'complete' => 'All required docs',
                        'none' => 'No required docs',
                    ])
                    ->query(function (Builder $query, array $data) {
                        $value = $data['value'] ?? null;
                        if (! $value) {
                            return $query;
                        }

                        $missingRequired = function ($q) {
                            $q->selectRaw('1')
                                ->from('service_requisition_documents as srd')
                                ->join('document_types as dt', 'dt.id', '=', 'srd.document_type_id')
                                ->whereColumn('srd.service_id', 'tickets.service_id')
                                ->whereColumn('srd.requisition_id', 'tickets.requisition_id')
                                ->where('srd.is_required', true)
                                ->where('dt.is_active', true)
                                ->whereNull('dt.deleted_at')
                                ->where('dt.code', '!=', 'document-if-any')
                                ->where('dt.name', 'not like', '%if any%')
                                ->whereNotExists(function ($q2) {
                                    $q2->selectRaw('1')
                                        ->from('ticket_documents as td')
                                        ->whereColumn('td.ticket_id', 'tickets.id')
                                        ->whereColumn('td.document_type_id', 'srd.document_type_id')
                                        ->whereNull('td.deleted_at');
                                });
                        };

                        $hasRequired = function ($q) {
                            $q->selectRaw('1')
                                ->from('service_requisition_documents as srd')
                                ->join('document_types as dt', 'dt.id', '=', 'srd.document_type_id')
                                ->whereColumn('srd.service_id', 'tickets.service_id')
                                ->whereColumn('srd.requisition_id', 'tickets.requisition_id')
                                ->where('srd.is_required', true)
                                ->where('dt.is_active', true)
                                ->whereNull('dt.deleted_at')
                                ->where('dt.code', '!=', 'document-if-any')
                                ->where('dt.name', 'not like', '%if any%');
                        };

                        return match ($value) {
                            'incomplete' => $query->whereExists($missingRequired),
                            'complete' => $query->whereExists($hasRequired)->whereNotExists($missingRequired),
                            'none' => $query->whereNotExists($hasRequired),
                            default => $query,
                        };
                    }),
            ])
            ->recordActions([
                ViewAction::make()
                    ->url(fn (Ticket $record): string => static::getUrl('view', ['record' => $record])),
                Action::make('send_sms')
                    ->label('Send SMS')
                    ->icon('heroicon-o-chat-bubble-left-ellipsis')
                    ->color('primary')
                    ->visible(fn (Ticket $record): bool => (bool) auth()->user()?->canSendTicketSms()
                        && filled(static::ticketSmsPhone($record)))
                    ->form([
                        Textarea::make('message')
                            ->label('SMS message')
                            ->required()
                            ->rows(5)
                            ->maxLength(640)
                            ->helperText(fn (Ticket $record): string => 'Ad-hoc SMS to contact '
                                .($record->contact?->phone_number ?: '—')
                                .' for request '.$record->tt_number
                                .'. Max 640 characters.'),
                    ])
                    ->requiresConfirmation()
                    ->modalHeading(fn (Ticket $record): string => 'Send SMS for '.$record->tt_number)
                    ->action(function (Ticket $record, array $data, SmsService $sms): void {
                        static::dispatchTicketSms($record, (string) $data['message'], $sms);
                    }),
                Action::make('assign_to_me')
                    ->label('Assign to me')
                    ->icon('heroicon-o-user-plus')
                    ->color('primary')
                    ->visible(fn (Ticket $record) => $record->status === TicketStatus::Open
                        && blank($record->assigned_to_user_id)
                        && auth()->user()?->isAssignableAccountManager())
                    ->requiresConfirmation()
                    ->modalHeading('Take this ticket')
                    ->modalDescription('Assign this service request to yourself as account manager.')
                    ->action(function (Ticket $record, TicketWorkflowService $workflow) {
                        $workflow->assign(
                            $record,
                            auth()->user(),
                            auth()->user(),
                            null,
                            'Self-assigned by account manager',
                        );
                    }),
                Action::make('assign')
                    ->label('Assign AM')
                    ->visible(fn (Ticket $record) => $record->status === TicketStatus::Open
                        && blank($record->assigned_to_user_id))
                    ->form([
                        Select::make('assigned_to_user_id')
                            ->label('Account manager')
                            ->options(fn (Ticket $record) => User::assignableManagersForCategory(
                                $record->category_id ? (int) $record->category_id : null
                            ))
                            ->required()
                            ->searchable()
                            ->preload(),
                        Select::make('priority_id')
                            ->relationship('priority', 'name')
                            ->searchable()
                            ->preload(),
                        Textarea::make('note'),
                    ])
                    ->action(function (Ticket $record, array $data, TicketWorkflowService $workflow) {
                        $workflow->assign(
                            $record,
                            auth()->user(),
                            User::findOrFail($data['assigned_to_user_id']),
                            $data['priority_id'] ?? null,
                            $data['note'] ?? null,
                        );
                    }),
                Action::make('verify_docs')
                    ->label('Verify docs')
                    ->visible(fn (Ticket $record) => $record->assigned_to_user_id === auth()->id()
                        && blank($record->current_approver_user_id)
                        && in_array($record->status, [
                            TicketStatus::Open,
                            TicketStatus::InProgress,
                            TicketStatus::Rejected,
                        ], true)
                        && $record->document_review_status !== DocumentReviewStatus::Passed)
                    ->form([
                        Select::make('result')->options([
                            DocumentReviewStatus::Passed->value => 'All documents OK',
                            DocumentReviewStatus::Failed->value => 'Documents missing/failed',
                        ])->required(),
                        Textarea::make('note'),
                    ])
                    ->action(function (Ticket $record, array $data, TicketWorkflowService $workflow) {
                        try {
                            $workflow->reviewDocuments(
                                $record,
                                auth()->user(),
                                DocumentReviewStatus::from($data['result']),
                                $data['note'] ?? null,
                            );
                        } catch (\Illuminate\Validation\ValidationException $e) {
                            $message = collect($e->errors())->flatten()->first() ?: $e->getMessage();
                            Notification::make()
                                ->title('Next approver is not found')
                                ->body((string) $message)
                                ->danger()
                                ->persistent()
                                ->send();

                            throw $e;
                        }
                    }),
                Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Ticket $record) => $record->current_approver_user_id === auth()->id())
                    ->modalHeading('Approve this request')
                    ->modalDescription(fn (): string => 'Logged as '.(auth()->user()?->name ?? 'you').' with a timestamp.')
                    ->form([
                        Textarea::make('note')->label('Note (optional)'),
                    ])
                    ->requiresConfirmation()
                    ->action(function (Ticket $record, array $data, TicketWorkflowService $workflow) {
                        try {
                            $workflow->decide(
                                $record,
                                auth()->user(),
                                ApprovalAction::Approved,
                                $data['note'] ?? null,
                            );
                        } catch (\Illuminate\Validation\ValidationException $e) {
                            $message = collect($e->errors())->flatten()->first() ?: $e->getMessage();
                            Notification::make()
                                ->title(
                                    str_contains(strtolower((string) $message), 'approver')
                                        ? 'Next approver is not found'
                                        : 'Approval failed'
                                )
                                ->body((string) $message)
                                ->danger()
                                ->persistent()
                                ->send();

                            throw $e;
                        }
                    }),
                Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Ticket $record) => $record->current_approver_user_id === auth()->id())
                    ->modalHeading('Reject this request')
                    ->modalDescription(fn (): string => 'Logged as '.(auth()->user()?->name ?? 'you').' with a timestamp. A reason is required.')
                    ->form([
                        Textarea::make('note')
                            ->label('Reason')
                            ->required()
                            ->minLength(3)
                            ->helperText('Shown on the approval log and used when sending the request back.'),
                    ])
                    ->requiresConfirmation()
                    ->action(function (Ticket $record, array $data, TicketWorkflowService $workflow) {
                        $workflow->decide(
                            $record,
                            auth()->user(),
                            ApprovalAction::Rejected,
                            $data['note'] ?? null,
                        );
                    }),
                Action::make('close')
                    ->label('Close')
                    ->visible(fn (Ticket $record) => $record->status === TicketStatus::Completed
                        && ($record->assigned_to_user_id === auth()->id() || auth()->user()?->is_management))
                    ->requiresConfirmation()
                    ->action(fn (Ticket $record, TicketWorkflowService $workflow) => $workflow->close($record, auth()->user())),
                DeleteAction::make()
                    ->visible(fn (Ticket $record): bool => (bool) auth()->user()?->can('delete', $record)
                        && $record->status === TicketStatus::Open)
                    ->modalHeading(fn (Ticket $record): string => 'Delete pending request '.$record->tt_number)
                    ->modalDescription('Only pending requests can be deleted. This cannot be undone from the list (soft-deleted).')
                    ->successNotificationTitle('Pending request deleted'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('send_sms')
                        ->label('Send SMS to selected')
                        ->icon('heroicon-o-chat-bubble-left-ellipsis')
                        ->color('primary')
                        ->visible(fn (): bool => (bool) auth()->user()?->canBulkSendTicketSms())
                        ->form([
                            Textarea::make('message')
                                ->label('SMS message')
                                ->required()
                                ->rows(5)
                                ->maxLength(640)
                                ->helperText('Ad-hoc SMS to each selected ticket contact. Max 640 characters.'),
                        ])
                        ->requiresConfirmation()
                        ->modalHeading('Send SMS to selected tickets')
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records, array $data, SmsService $sms): void {
                            $result = static::queueSmsToTickets(
                                $records,
                                (string) ($data['message'] ?? ''),
                                $sms,
                            );

                            Notification::make()
                                ->title($result['queued'] > 0
                                    ? "Queued SMS for {$result['queued']} ticket(s)"
                                    : 'No SMS queued')
                                ->body($result['skipped'] > 0
                                    ? "{$result['skipped']} skipped (missing or invalid contact phone)."
                                    : null)
                                ->color($result['queued'] > 0 ? 'success' : 'warning')
                                ->send();
                        }),
                    BulkAction::make('assign')
                        ->label('Assign AM')
                        ->icon('heroicon-o-user-plus')
                        ->color('primary')
                        ->form([
                            Select::make('assigned_to_user_id')
                                ->label('Account manager')
                                ->options(fn () => User::assignableManagersForCategory(null))
                                ->required()
                                ->searchable()
                                ->preload(),
                            Select::make('priority_id')
                                ->label('Priority')
                                ->options(fn () => Priority::query()->orderBy('name')->pluck('name', 'id'))
                                ->searchable()
                                ->preload(),
                            Textarea::make('note'),
                        ])
                        ->action(function (Collection $records, array $data, TicketWorkflowService $workflow): void {
                            $assignee = User::findOrFail($data['assigned_to_user_id']);
                            $assigner = auth()->user();
                            $assigned = 0;
                            $skipped = 0;

                            foreach ($records as $ticket) {
                                if ($ticket->status !== TicketStatus::Open || filled($ticket->assigned_to_user_id)) {
                                    $skipped++;

                                    continue;
                                }

                                try {
                                    $workflow->assign(
                                        $ticket,
                                        $assigner,
                                        $assignee,
                                        $data['priority_id'] ?? null,
                                        $data['note'] ?? null,
                                    );
                                    $assigned++;
                                } catch (Throwable) {
                                    $skipped++;
                                }
                            }

                            if ($assigned > 0) {
                                Notification::make()
                                    ->title("Assigned {$assigned} ticket(s)")
                                    ->body($skipped > 0 ? "{$skipped} skipped (already assigned or not open)." : null)
                                    ->success()
                                    ->send();
                            } else {
                                Notification::make()
                                    ->title('No tickets assigned')
                                    ->body('Only open, unassigned tickets can be bulk-assigned.')
                                    ->warning()
                                    ->send();
                            }
                        })
                        ->deselectRecordsAfterCompletion(),
                    DeleteBulkAction::make()
                        ->label('Delete pending')
                        ->authorizeIndividualRecords('delete')
                        ->visible(fn (): bool => (bool) auth()->user()?->can('Delete:Ticket'))
                        ->modalHeading('Delete selected pending requests')
                        ->modalDescription('Only pending (not started) tickets will be deleted. In progress, completed, rejected, or closed tickets are skipped.')
                        ->successNotificationTitle('Pending requests deleted')
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }

    public static function ticketSmsPhone(Ticket $ticket): ?string
    {
        $ticket->loadMissing('contact');
        $phone = $ticket->contact?->phone_number;

        return filled($phone) ? (string) $phone : null;
    }

    /**
     * @param  Builder<Ticket>|Collection<int, Ticket>|iterable<Ticket>  $tickets
     * @return array{queued:int, skipped:int}
     */
    public static function queueSmsToTickets(Builder|iterable $tickets, string $message, SmsService $sms): array
    {
        $message = trim($message);
        $queued = 0;
        $skipped = 0;

        if ($message === '') {
            return ['queued' => 0, 'skipped' => 0];
        }

        $iterator = $tickets instanceof Builder
            ? $tickets->with('contact')->cursor()
            : $tickets;

        foreach ($iterator as $ticket) {
            if (! $ticket instanceof Ticket) {
                continue;
            }

            if (! auth()->user()?->canSendTicketSms() && ! auth()->user()?->canBulkSendTicketSms()) {
                $skipped++;

                continue;
            }

            $phone = static::ticketSmsPhone($ticket);
            if (! filled($phone) || ! $sms->ensurePhoneIsLocal($phone)) {
                $skipped++;

                continue;
            }

            $sms->send($phone, $message);
            $queued++;
        }

        return ['queued' => $queued, 'skipped' => $skipped];
    }

    public static function dispatchTicketSms(Ticket $ticket, string $message, SmsService $sms): void
    {
        $result = static::queueSmsToTickets([$ticket], $message, $sms);
        $phone = static::ticketSmsPhone($ticket);

        if ($result['queued'] < 1) {
            Notification::make()
                ->title('Cannot send SMS')
                ->body('Ticket contact has no usable local mobile number on file.')
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title('SMS queued')
            ->body('Message queued for '.$phone.' ('.$ticket->tt_number.')')
            ->success()
            ->send();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTickets::route('/'),
            'view' => ViewTicket::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->hasRole('super_admin')) {
            return $query;
        }

        $scopeIds = $user->scopedCategoryIds();

        // Staff with group scope: tickets in those groups, plus anything assigned to them.
        if ($scopeIds->isNotEmpty()) {
            return $query->where(function (Builder $q) use ($user, $scopeIds) {
                $q->whereIn('category_id', $scopeIds->all())
                    ->orWhere('assigned_to_user_id', $user->id)
                    ->orWhere('current_approver_user_id', $user->id);
            });
        }

        if ($user->can('ViewAny:Ticket')) {
            return $query;
        }

        // Account managers without group scope: only assigned / awaiting approval.
        return $query->where(function (Builder $q) use ($user) {
            $q->where('assigned_to_user_id', $user->id)
                ->orWhere('current_approver_user_id', $user->id);
        });
    }
}
