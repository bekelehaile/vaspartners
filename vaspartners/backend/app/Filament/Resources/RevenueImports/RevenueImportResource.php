<?php

namespace App\Filament\Resources\RevenueImports;

use App\Enums\RevenueImportStatus;
use App\Filament\Resources\BulkMessages\BulkMessageResource;
use App\Filament\Resources\RevenueImports\Pages\ListRevenueImports;
use App\Filament\Resources\RevenueImports\Pages\ViewRevenueImport;
use App\Filament\Resources\RevenueImports\RelationManagers\RowsRelationManager;
use App\Models\RevenueImport;
use App\Models\User;
use App\Support\RevenueCatalogServices;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class RevenueImportResource extends Resource
{
    protected static ?string $model = RevenueImport::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static string|\UnitEnum|null $navigationGroup = 'Partners';

    protected static ?string $navigationLabel = 'Monthly revenue';

    protected static ?string $modelLabel = 'Monthly revenue';

    protected static ?string $pluralModelLabel = 'Monthly revenue';

    protected static ?string $slug = 'monthly-revenue';

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?int $navigationSort = 6;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Import')->schema([
                TextEntry::make('title'),
                TextEntry::make('period')->label('Month'),
                TextEntry::make('vasService.name')->label('Catalog service'),
                TextEntry::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof RevenueImportStatus ? $state->label() : (string) $state)
                    ->color(fn ($state) => match ($state instanceof RevenueImportStatus ? $state : RevenueImportStatus::tryFrom((string) $state)) {
                        RevenueImportStatus::Ready, RevenueImportStatus::Completed => 'success',
                        RevenueImportStatus::Reviewing => 'warning',
                        RevenueImportStatus::Failed => 'danger',
                        RevenueImportStatus::Sending => 'info',
                        default => 'gray',
                    }),
                TextEntry::make('creator.name')->label('Imported by')->placeholder('—'),
                TextEntry::make('imported_at')->label('Imported at')->dateTime()->placeholder('—'),
                TextEntry::make('sender.name')->label('Sent by')->placeholder('—'),
                TextEntry::make('sent_at')->label('Sent at')->dateTime()->placeholder('—'),
                TextEntry::make('source_filename')->label('CSV file')->placeholder('—'),
                TextEntry::make('bulkMessage.title')
                    ->label('Bulk SMS campaign')
                    ->placeholder('—')
                    ->url(fn (RevenueImport $record): ?string => $record->bulkMessage
                        ? BulkMessageResource::getUrl('view', ['record' => $record->bulkMessage])
                        : null),
                TextEntry::make('message_template')->label('SMS template')->columnSpanFull(),
            ])->columns(2),
            Section::make('Row counts')->schema([
                TextEntry::make('total_count')->label('Total'),
                TextEntry::make('matched_count')->label('Ready'),
                TextEntry::make('missing_partner_count')->label('Unresolved'),
                TextEntry::make('missing_phone_count')->label('Missing phone'),
                TextEntry::make('invalid_count')->label('Invalid / duplicate'),
            ])->columns(5),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable()->sortable(),
                TextColumn::make('period')->label('Month')->sortable(),
                TextColumn::make('vasService.name')->label('Catalog service')->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof RevenueImportStatus ? $state->label() : (string) $state)
                    ->color(fn ($state) => match ($state instanceof RevenueImportStatus ? $state : RevenueImportStatus::tryFrom((string) $state)) {
                        RevenueImportStatus::Ready, RevenueImportStatus::Completed => 'success',
                        RevenueImportStatus::Reviewing => 'warning',
                        RevenueImportStatus::Failed => 'danger',
                        RevenueImportStatus::Sending => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('creator.name')->label('Imported by')->toggleable(),
                TextColumn::make('imported_at')->dateTime()->sortable(),
                TextColumn::make('sender.name')->label('Sent by')->toggleable(),
                TextColumn::make('sent_at')->dateTime()->toggleable(),
                TextColumn::make('matched_count')->label('Ready'),
                TextColumn::make('missing_partner_count')->label('Unresolved')->toggleable(),
                TextColumn::make('missing_phone_count')->label('Missing phone')->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('vas_service_id')
                    ->label('Catalog service')
                    ->options(fn (): array => RevenueCatalogServices::options(auth()->user())),
            ])
            ->recordActions([
                ViewAction::make()
                    ->url(fn (RevenueImport $record): string => static::getUrl('view', ['record' => $record])),
            ])
            ->toolbarActions([]);
    }

    public static function getRelations(): array
    {
        return [
            RowsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRevenueImports::route('/'),
            'view' => ViewRevenueImport::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with('vasService');
        /** @var User|null $user */
        $user = auth()->user();

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->canAccessAllRevenue()) {
            return $query;
        }

        $serviceIds = $user->managedRevenueServiceIds();
        if ($serviceIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->where('created_by_user_id', $user->id)
            ->whereIn('vas_service_id', $serviceIds);
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

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function canForceDelete(Model $record): bool
    {
        return false;
    }

    public static function canForceDeleteAny(): bool
    {
        return false;
    }
}
