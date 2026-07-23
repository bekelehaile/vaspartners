<?php

namespace App\Filament\Resources\CompanyChangeRequests;

use App\Enums\CompanyChangeStatus;
use App\Enums\CompanyChangeType;
use App\Filament\Resources\CompanyChangeRequests\Pages\ListCompanyChangeRequests;
use App\Filament\Resources\CompanyChangeRequests\Pages\ViewCompanyChangeRequest;
use App\Models\CompanyChangeRequest;
use App\Services\CompanyMembershipService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class CompanyChangeRequestResource extends Resource
{
    protected static ?string $model = CompanyChangeRequest::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-office-2';

    protected static string|\UnitEnum|null $navigationGroup = 'Partners';

    protected static ?string $navigationLabel = 'Company requests';

    protected static ?string $modelLabel = 'Company request';

    protected static ?string $recordTitleAttribute = 'public_id';

    protected static ?int $navigationSort = 2;

    public static function getNavigationBadge(): ?string
    {
        $count = CompanyChangeRequest::query()
            ->where('status', CompanyChangeStatus::Pending)
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Request')->schema([
                TextEntry::make('public_id')->label('Request ID'),
                TextEntry::make('type')->badge()->formatStateUsing(fn ($state) => $state instanceof CompanyChangeType ? $state->label() : (string) $state),
                TextEntry::make('status')->badge()->color(fn ($state) => match ($state instanceof CompanyChangeStatus ? $state->value : $state) {
                    'pending' => 'warning',
                    'approved' => 'success',
                    'rejected' => 'danger',
                    default => 'gray',
                }),
                TextEntry::make('created_at')->dateTime(),
                TextEntry::make('customer_note')->label('Partner note')->columnSpanFull()->placeholder('—'),
                TextEntry::make('admin_note')->label('Admin note')->columnSpanFull()->placeholder('—'),
            ])->columns(2),
            Section::make('Partner')->schema([
                TextEntry::make('customer.name'),
                TextEntry::make('customer.phone_number'),
                TextEntry::make('customer.email'),
                TextEntry::make('customer.identification_number')->label('ID number'),
            ])->columns(2),
            Section::make('Company')->schema([
                TextEntry::make('company.name'),
                TextEntry::make('company.tin')->label('TIN'),
                TextEntry::make('company.phone'),
                TextEntry::make('company.email'),
                TextEntry::make('company.address')->columnSpanFull(),
            ])->columns(2),
            Section::make('Detach documents')
                ->visible(fn (CompanyChangeRequest $record): bool => $record->type === CompanyChangeType::Detach)
                ->schema([
                    TextEntry::make('proposal_original_name')->label('Proposal PDF')->placeholder('—'),
                    TextEntry::make('letter_original_name')->label('Letter PDF')->placeholder('—'),
                ])->columns(2),
            Section::make('Decision')->schema([
                TextEntry::make('reviewer.name')->label('Reviewed by')->placeholder('—'),
                TextEntry::make('reviewed_at')->dateTime()->placeholder('—'),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('public_id')->label('ID')->searchable()->toggleable(),
                TextColumn::make('type')->badge()->formatStateUsing(fn ($state) => $state instanceof CompanyChangeType ? $state->label() : (string) $state),
                TextColumn::make('status')->badge()->color(fn ($state) => match ($state instanceof CompanyChangeStatus ? $state->value : $state) {
                    'pending' => 'warning',
                    'approved' => 'success',
                    'rejected' => 'danger',
                    default => 'gray',
                }),
                TextColumn::make('customer.name')->label('Partner')->searchable(),
                TextColumn::make('company.name')->label('Company')->searchable(),
                TextColumn::make('company.tin')->label('TIN'),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(collect(CompanyChangeStatus::cases())->mapWithKeys(
                    fn (CompanyChangeStatus $s) => [$s->value => $s->label()]
                )),
                SelectFilter::make('type')->options(collect(CompanyChangeType::cases())->mapWithKeys(
                    fn (CompanyChangeType $t) => [$t->value => $t->label()]
                )),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('approve')
                    ->label('Approve')
                    ->color('success')
                    ->icon('heroicon-o-check-circle')
                    ->visible(fn (CompanyChangeRequest $record) => $record->status === CompanyChangeStatus::Pending)
                    ->form([
                        Textarea::make('admin_note')->label('Note to partner (optional)'),
                    ])
                    ->requiresConfirmation()
                    ->action(function (CompanyChangeRequest $record, array $data, CompanyMembershipService $membership) {
                        $membership->approve($record, auth()->user(), $data['admin_note'] ?? null);
                    }),
                Action::make('reject')
                    ->label('Reject')
                    ->color('danger')
                    ->icon('heroicon-o-x-circle')
                    ->visible(fn (CompanyChangeRequest $record) => $record->status === CompanyChangeStatus::Pending)
                    ->form([
                        Textarea::make('admin_note')->label('Reason for partner')->required(),
                    ])
                    ->requiresConfirmation()
                    ->action(function (CompanyChangeRequest $record, array $data, CompanyMembershipService $membership) {
                        $membership->reject($record, auth()->user(), $data['admin_note'] ?? null);
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCompanyChangeRequests::route('/'),
            'view' => ViewCompanyChangeRequest::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }
}
