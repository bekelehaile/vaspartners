<?php

namespace App\Filament\Resources\Companies;

use App\Enums\CompanyApprovalStatus;
use App\Filament\Resources\Companies\Pages\EditCompany;
use App\Filament\Resources\Companies\Pages\ListCompanies;
use App\Filament\Resources\Companies\Pages\ViewCompany;
use App\Filament\Resources\Companies\RelationManagers\ChangeRequestsRelationManager;
use App\Filament\Resources\Companies\RelationManagers\MembersRelationManager;
use App\Filament\Resources\Companies\RelationManagers\SubscriptionsRelationManager;
use App\Models\Company;
use App\Services\CompanyMembershipService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Throwable;

class CompanyResource extends Resource
{
    protected static ?string $model = Company::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-office';

    protected static string|\UnitEnum|null $navigationGroup = 'Partners';

    protected static ?string $navigationLabel = 'Companies';

    protected static ?string $modelLabel = 'Company';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 2;

    public static function getNavigationBadge(): ?string
    {
        $count = Company::query()
            ->where('approval_status', CompanyApprovalStatus::Pending)
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('tin')
                ->label('TIN')
                ->required()
                ->unique(ignoreRecord: true)
                ->maxLength(64)
                ->helperText('Must be unique across all companies. Partners cannot use services until this profile is approved.'),
            TextInput::make('license_number')
                ->label('License number')
                ->required()
                ->unique(ignoreRecord: true)
                ->maxLength(64),
            TextInput::make('phone')->tel()->maxLength(32),
            TextInput::make('email')->email()->maxLength(255),
            Textarea::make('address')->rows(3)->columnSpanFull(),
            Toggle::make('is_active')
                ->label('Active')
                ->helperText('Normally set by Approve profile. Inactive companies cannot use VAS services.'),
        ])->columns(2);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Company')->schema([
                TextEntry::make('public_id')->label('ID'),
                TextEntry::make('name'),
                TextEntry::make('tin')->label('TIN'),
                TextEntry::make('license_number')->label('License number'),
                TextEntry::make('phone')->placeholder('—'),
                TextEntry::make('email')->placeholder('—'),
                TextEntry::make('approval_status')
                    ->label('Approval')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof CompanyApprovalStatus
                        ? $state->label()
                        : (CompanyApprovalStatus::tryFrom((string) $state)?->label() ?? (string) $state))
                    ->color(fn ($state) => match ($state instanceof CompanyApprovalStatus ? $state->value : $state) {
                        'approved' => 'success',
                        'rejected' => 'danger',
                        default => 'warning',
                    }),
                TextEntry::make('is_active')->badge()->formatStateUsing(fn ($state) => $state ? 'Active' : 'Inactive')
                    ->color(fn ($state) => $state ? 'success' : 'gray'),
                TextEntry::make('owner_name')
                    ->label('Owner')
                    ->state(fn (Company $record): ?string => $record->ownerCustomer()?->name)
                    ->placeholder('No owner')
                    ->color('success'),
                TextEntry::make('members_count')
                    ->label('Total people')
                    ->state(fn (Company $record): int => $record->memberCount()),
                TextEntry::make('approvedBy.name')->label('Approved / reviewed by')->placeholder('—'),
                TextEntry::make('approved_at')->dateTime()->placeholder('—'),
                TextEntry::make('approval_note')->label('Approval note')->columnSpanFull()->placeholder('—'),
                TextEntry::make('address')->columnSpanFull()->placeholder('—'),
                TextEntry::make('created_at')->dateTime(),
                TextEntry::make('creator.name')->label('Created by partner')->placeholder('—'),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('tin')->label('TIN')->searchable()->sortable(),
                TextColumn::make('license_number')->label('License')->searchable()->sortable(),
                TextColumn::make('owner_name')
                    ->label('Owner')
                    ->state(fn (Company $record): ?string => $record->ownerCustomer()?->name)
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('approval_status')
                    ->label('Approval')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof CompanyApprovalStatus
                        ? $state->label()
                        : (CompanyApprovalStatus::tryFrom((string) $state)?->label() ?? (string) $state))
                    ->color(fn ($state) => match ($state instanceof CompanyApprovalStatus ? $state->value : $state) {
                        'approved' => 'success',
                        'rejected' => 'danger',
                        default => 'warning',
                    }),
                TextColumn::make('members_count')
                    ->counts('memberships')
                    ->label('Members')
                    ->sortable(),
                IconColumn::make('is_active')->boolean()->label('Active'),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('approval_status')->options([
                    CompanyApprovalStatus::Pending->value => CompanyApprovalStatus::Pending->label(),
                    CompanyApprovalStatus::Approved->value => CompanyApprovalStatus::Approved->label(),
                    CompanyApprovalStatus::Rejected->value => CompanyApprovalStatus::Rejected->label(),
                ]),
                TernaryFilter::make('is_active')->label('Active'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                Action::make('approve')
                    ->label('Approve')
                    ->color('success')
                    ->icon('heroicon-o-check-circle')
                    ->visible(fn (Company $record): bool => ! $record->isApproved())
                    ->form([
                        Textarea::make('approval_note')->label('Note to partner (optional)'),
                    ])
                    ->requiresConfirmation()
                    ->modalHeading('Approve company profile')
                    ->modalDescription('Confirm all required company information is complete. The creating partner remains the owner.')
                    ->action(function (Company $record, array $data, CompanyMembershipService $membership): void {
                        try {
                            $membership->approveCompany($record, auth()->user(), $data['approval_note'] ?? null);
                            Notification::make()->title('Company approved')->success()->send();
                        } catch (Throwable $e) {
                            Notification::make()->title('Could not approve')->body($e->getMessage())->danger()->send();
                        }
                    }),
                Action::make('reject')
                    ->label('Reject')
                    ->color('danger')
                    ->icon('heroicon-o-x-circle')
                    ->visible(fn (Company $record): bool => ! $record->isApproved()
                        && ($record->approval_status !== CompanyApprovalStatus::Rejected))
                    ->form([
                        Textarea::make('approval_note')->label('What is missing / needs correction')->required(),
                    ])
                    ->requiresConfirmation()
                    ->action(function (Company $record, array $data, CompanyMembershipService $membership): void {
                        try {
                            $membership->rejectCompany($record, auth()->user(), $data['approval_note'] ?? null);
                            Notification::make()->title('Company rejected')->warning()->send();
                        } catch (Throwable $e) {
                            Notification::make()->title('Could not reject')->body($e->getMessage())->danger()->send();
                        }
                    }),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            MembersRelationManager::class,
            SubscriptionsRelationManager::class,
            ChangeRequestsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCompanies::route('/'),
            'view' => ViewCompany::route('/{record}'),
            'edit' => EditCompany::route('/{record}/edit'),
        ];
    }

    public static function canCreate(): bool
    {
        // Companies are created by partners (create/attach flow).
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canForceDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function canForceDeleteAny(): bool
    {
        return false;
    }
}
