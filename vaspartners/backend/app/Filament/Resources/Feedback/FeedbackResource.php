<?php

namespace App\Filament\Resources\Feedback;

use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Companies\CompanyResource;
use App\Filament\Resources\Feedback\Pages\ListFeedback;
use App\Filament\Resources\Feedback\Pages\ViewFeedback;
use App\Models\Feedback;
use Filament\Actions\DeleteAction;
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

class FeedbackResource extends Resource
{
    protected static ?string $model = Feedback::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-star';

    protected static string|\UnitEnum|null $navigationGroup = 'Partners';

    protected static ?string $navigationLabel = 'Feedback';

    protected static ?string $modelLabel = 'Feedback';

    protected static ?string $pluralModelLabel = 'Feedback';

    protected static ?int $navigationSort = 40;

    protected static ?string $recordTitleAttribute = 'public_id';

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
            Section::make('Quarter')->schema([
                TextEntry::make('year')->label('Year'),
                TextEntry::make('quarter')
                    ->label('Quarter')
                    ->formatStateUsing(fn ($state, Feedback $record): string => $record->quarterLabel()),
                TextEntry::make('rating')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state.'/5')
                    ->color(fn ($state): string => match ((int) $state) {
                        5, 4 => 'success',
                        3 => 'warning',
                        default => 'danger',
                    }),
                TextEntry::make('updated_at')->label('Submitted')->dateTime(),
            ])->columns(4),
            Section::make('Partner')->schema([
                TextEntry::make('contact.name')
                    ->label('Contact')
                    ->url(fn (Feedback $record): ?string => $record->contact
                        ? ContactResource::getUrl('view', ['record' => $record->contact])
                        : null),
                TextEntry::make('contact.phone_number')->label('Phone')->placeholder('—'),
                TextEntry::make('contact.email')->label('Email')->placeholder('—'),
                TextEntry::make('company.name')
                    ->label('Company')
                    ->placeholder('—')
                    ->url(fn (Feedback $record): ?string => $record->company
                        ? CompanyResource::getUrl('view', ['record' => $record->company])
                        : null),
            ])->columns(2),
            Section::make('Feedback')->schema([
                TextEntry::make('description')->columnSpanFull()->prose(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $yearOptions = Feedback::query()
            ->select('year')
            ->distinct()
            ->orderByDesc('year')
            ->pluck('year', 'year')
            ->all();

        if ($yearOptions === []) {
            $yearOptions = [Feedback::currentYear() => Feedback::currentYear()];
        }

        return $table
            ->defaultSort('updated_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['contact', 'company']))
            ->columns([
                TextColumn::make('quarter_label')
                    ->label('Period')
                    ->state(fn (Feedback $record): string => $record->quarterLabel())
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query->orderBy('year', $direction)->orderBy('quarter', $direction);
                    }),
                TextColumn::make('rating')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state.'/5')
                    ->color(fn ($state): string => match ((int) $state) {
                        5, 4 => 'success',
                        3 => 'warning',
                        default => 'danger',
                    })
                    ->sortable(),
                TextColumn::make('contact.name')->label('Contact')->searchable()->placeholder('—'),
                TextColumn::make('company.name')->label('Company')->searchable()->placeholder('—'),
                TextColumn::make('description')->limit(50)->wrap()->toggleable(),
                TextColumn::make('updated_at')->label('Submitted')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('year')
                    ->options($yearOptions),
                SelectFilter::make('quarter')
                    ->options([
                        1 => 'Q1',
                        2 => 'Q2',
                        3 => 'Q3',
                        4 => 'Q4',
                    ]),
                SelectFilter::make('rating')
                    ->options([
                        5 => '5 stars',
                        4 => '4 stars',
                        3 => '3 stars',
                        2 => '2 stars',
                        1 => '1 star',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFeedback::route('/'),
            'view' => ViewFeedback::route('/{record}'),
        ];
    }
}
