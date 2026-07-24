<?php

namespace App\Filament\Resources\Tickets\RelationManagers;

use App\Enums\DocumentReviewStatus;
use App\Models\TicketDocumentReview;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class DocumentReviewsRelationManager extends RelationManager
{
    protected static string $relationship = 'documentReviews';

    protected static ?string $title = 'Document reviews';

    public function isReadOnly(): bool
    {
        return true;
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = $ownerRecord->documentReviews()->count();

        return $count > 0 ? (string) $count : null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->description('Who verified attachments and when, including pass/fail outcomes.')
            ->modifyQueryUsing(fn ($query) => $query->with('reviewer')->oldest('id'))
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('reviewer.name')
                    ->label('Reviewed by')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('result')
                    ->label('Result')
                    ->badge()
                    ->formatStateUsing(function ($state): string {
                        if ($state instanceof DocumentReviewStatus) {
                            return $state->label();
                        }

                        return DocumentReviewStatus::tryFrom((string) $state)?->label() ?? (string) $state;
                    })
                    ->color(fn ($state): string => match ($state instanceof DocumentReviewStatus ? $state->value : (string) $state) {
                        'passed' => 'success',
                        'failed' => 'danger',
                        'pending' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('note')
                    ->label('Note')
                    ->wrap()
                    ->limit(80)
                    ->placeholder('—'),
            ])
            ->defaultSort('id')
            ->paginated([10, 25, 50])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
