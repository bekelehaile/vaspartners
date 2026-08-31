<?php

namespace App\Filament\Resources\RevenueImportRows;

use App\Enums\BulkMessageRecipientStatus;
use App\Enums\RevenueImportRowStatus;
use App\Enums\RevenueImportStatus;
use App\Filament\Resources\RevenueImportRows\Pages\ListRevenueImportRows;
use App\Filament\Resources\RevenueImportRows\Pages\ViewRevenueImportRow;
use App\Filament\Resources\RevenueImports\RevenueImportResource;
use App\Filament\Resources\RevenuePartners\RevenuePartnerResource;
use App\Models\AppSetting;
use App\Models\RevenueImportRow;
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

class RevenueImportRowResource extends Resource
{
    protected static ?string $model = RevenueImportRow::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-currency-dollar';

    protected static string|\UnitEnum|null $navigationGroup = 'Partners';

    protected static ?string $navigationLabel = 'Partner revenue';

    protected static ?string $modelLabel = 'Partner revenue';

    protected static ?string $pluralModelLabel = 'Partner revenue';

    protected static ?string $slug = 'partner-revenue';

    protected static ?string $recordTitleAttribute = 'partner_name';

    protected static ?int $navigationSort = 7;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Partner')
                ->schema([
                    TextEntry::make('partner_name')
                        ->label('Partner name')
                        ->placeholder('—'),
                    TextEntry::make('service_id')
                        ->label('Service ID')
                        ->copyable(),
                    TextEntry::make('short_code')
                        ->label('Short code')
                        ->placeholder('—')
                        ->copyable(),
                    TextEntry::make('partner.phone')
                        ->label('Phone')
                        ->placeholder('—'),
                    TextEntry::make('partner.company.name')
                        ->label('Linked company')
                        ->placeholder('—')
                        ->url(fn (RevenueImportRow $record): ?string => $record->partner?->company_id
                            ? \App\Filament\Resources\Companies\CompanyResource::getUrl('view', ['record' => $record->partner->company_id])
                            : null),
                    TextEntry::make('partner.partner_name')
                        ->label('Master partner')
                        ->placeholder('—')
                        ->url(fn (RevenueImportRow $record): ?string => $record->revenue_partner_id
                            ? RevenuePartnerResource::getUrl('view', ['record' => $record->revenue_partner_id])
                            : null),
                ])
                ->columns(2),
            Section::make('Revenue')
                ->schema([
                    TextEntry::make('amount')
                        ->label('Amount (ETB)')
                        ->numeric(decimalPlaces: 2),
                    TextEntry::make('amount_raw')
                        ->label('Raw amount')
                        ->placeholder('—'),
                    TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn ($state) => $state instanceof RevenueImportRowStatus
                            ? $state->label()
                            : (string) $state)
                        ->color(fn ($state) => ($state instanceof RevenueImportRowStatus
                            ? $state
                            : RevenueImportRowStatus::tryFrom((string) $state))?->color() ?? 'gray'),
                    TextEntry::make('error')
                        ->placeholder('—')
                        ->columnSpanFull(),
                ])
                ->columns(2),
            Section::make('Monthly import')
                ->schema([
                    TextEntry::make('import.period')
                        ->label('Period'),
                    TextEntry::make('import.title')
                        ->label('Import title')
                        ->url(fn (RevenueImportRow $record): ?string => $record->import
                            ? RevenueImportResource::getUrl('view', ['record' => $record->import])
                            : null),
                    TextEntry::make('vasService.name')
                        ->label('Catalog service')
                        ->placeholder('—'),
                    TextEntry::make('import.status')
                        ->label('Import status')
                        ->badge()
                        ->formatStateUsing(fn ($state) => $state instanceof RevenueImportStatus
                            ? $state->label()
                            : (string) $state)
                        ->color(fn ($state) => match ($state instanceof RevenueImportStatus
                            ? $state
                            : RevenueImportStatus::tryFrom((string) $state)) {
                            RevenueImportStatus::Ready, RevenueImportStatus::Completed => 'success',
                            RevenueImportStatus::Reviewing => 'warning',
                            RevenueImportStatus::Failed => 'danger',
                            RevenueImportStatus::Sending => 'info',
                            default => 'gray',
                        }),
                    TextEntry::make('import.imported_at')
                        ->label('Imported at')
                        ->dateTime()
                        ->placeholder('—'),
                    TextEntry::make('sent_at')
                        ->label('Sent at')
                        ->dateTime()
                        ->placeholder('—'),
                ])
                ->columns(2),
            Section::make('SMS delivery')
                ->schema([
                    TextEntry::make('smsRecipient.status')
                        ->label('SMS status')
                        ->badge()
                        ->placeholder('Not queued')
                        ->formatStateUsing(fn ($state) => $state instanceof BulkMessageRecipientStatus
                            ? $state->label()
                            : (BulkMessageRecipientStatus::tryFrom((string) $state)?->label() ?? (string) $state))
                        ->color(fn ($state) => match ($state instanceof BulkMessageRecipientStatus
                            ? $state
                            : BulkMessageRecipientStatus::tryFrom((string) $state)) {
                            BulkMessageRecipientStatus::Sent => 'success',
                            BulkMessageRecipientStatus::Pending => 'info',
                            BulkMessageRecipientStatus::Failed => 'danger',
                            BulkMessageRecipientStatus::Skipped => 'gray',
                            default => 'gray',
                        }),
                    TextEntry::make('smsRecipient.sent_at')
                        ->label('SMS sent at')
                        ->dateTime()
                        ->placeholder('—'),
                    TextEntry::make('smsRecipient.error')
                        ->label('SMS error')
                        ->placeholder('—')
                        ->columnSpanFull(),
                ])
                ->columns(2)
                ->visible(fn (RevenueImportRow $record): bool => $record->wasSent()
                    || $record->smsRecipient !== null),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('import.period')
                    ->label('Period')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('partner_name')
                    ->label('Partner')
                    ->searchable()
                    ->wrap()
                    ->placeholder('—'),
                TextColumn::make('service_id')
                    ->label('Service ID')
                    ->searchable()
                    ->copyable(),
                TextColumn::make('short_code')
                    ->label('Short code')
                    ->searchable()
                    ->toggleable()
                    ->placeholder('—'),
                TextColumn::make('partner.phone')
                    ->label('Phone')
                    ->searchable()
                    ->toggleable()
                    ->placeholder('—'),
                TextColumn::make('partner.company.name')
                    ->label('Company')
                    ->searchable()
                    ->toggleable()
                    ->placeholder('—'),
                TextColumn::make('vasService.name')
                    ->label('Service')
                    ->toggleable(),
                TextColumn::make('amount')
                    ->label('Amount (ETB)')
                    ->numeric(decimalPlaces: 2)
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof RevenueImportRowStatus
                        ? $state->label()
                        : (string) $state)
                    ->color(fn ($state) => ($state instanceof RevenueImportRowStatus
                        ? $state
                        : RevenueImportRowStatus::tryFrom((string) $state))?->color() ?? 'gray'),
                TextColumn::make('import.title')
                    ->label('Import')
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->searchable(),
                TextColumn::make('sent_at')
                    ->label('Sent')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('period')
                    ->label('Period')
                    ->options(fn (): array => RevenueImportRow::query()
                        ->join('revenue_imports', 'revenue_imports.id', '=', 'revenue_import_rows.revenue_import_id')
                        ->whereNotNull('revenue_imports.period')
                        ->distinct()
                        ->orderByDesc('revenue_imports.period')
                        ->pluck('revenue_imports.period', 'revenue_imports.period')
                        ->all())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereHas('import', fn (Builder $q) => $q->where('period', $data['value']))
                        : $query),
                SelectFilter::make('status')
                    ->options(collect(RevenueImportRowStatus::cases())
                        ->mapWithKeys(fn (RevenueImportRowStatus $s) => [$s->value => $s->label()])
                        ->all()),
                SelectFilter::make('vas_service_id')
                    ->label('Catalog service')
                    ->options(fn (): array => RevenueCatalogServices::options())
                    ->searchable(),
                SelectFilter::make('revenue_partner_id')
                    ->label('Master partner')
                    ->relationship(
                        'partner',
                        'partner_name',
                        fn (Builder $query) => $query->where('is_active', true)->orderBy('partner_name'),
                    )
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRevenueImportRows::route('/'),
            'view' => ViewRevenueImportRow::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->with(['import', 'partner.company', 'vasService', 'smsRecipient']);

        /** @var User|null $user */
        $user = auth()->user();

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->canAccessAllRevenue()) {
            return $query;
        }

        $ownerIds = AppSetting::revenueOwnerIdsFor($user);
        if ($ownerIds === null) {
            return $query;
        }

        return $query->whereHas('import', fn (Builder $q) => $q->whereIn('created_by_user_id', $ownerIds));
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        /** @var RevenueImportRow $record */
        $period = $record->import?->period ?? '—';
        $name = $record->partner_name ?: $record->service_id ?: "#{$record->id}";

        return "{$name} · {$period}";
    }

    /**
     * @return array<string, string>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var RevenueImportRow $record */
        return array_filter([
            'Service ID' => $record->service_id,
            'Amount' => number_format((float) $record->amount, 2).' ETB',
            'Status' => $record->status instanceof RevenueImportRowStatus
                ? $record->status->label()
                : (string) $record->status,
        ]);
    }

    public static function getGloballySearchableAttributes(): array
    {
        return [
            'partner_name',
            'service_id',
            'short_code',
            'import.period',
            'partner.phone',
            'partner.company.name',
        ];
    }
}
