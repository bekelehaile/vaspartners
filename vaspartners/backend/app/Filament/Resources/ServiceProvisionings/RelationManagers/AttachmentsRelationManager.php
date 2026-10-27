<?php

namespace App\Filament\Resources\ServiceProvisionings\RelationManagers;

use App\Models\ServiceProvisioningMedia;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'media';

    protected static ?string $title = 'Attachments';

    public function isReadOnly(): bool
    {
        return true;
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = $ownerRecord->media()->count();

        return $count > 0 ? (string) $count : null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->description('Files uploaded on activation steps (letters, proposals, and other attachments).')
            ->modifyQueryUsing(fn ($query) => $query->with(['step', 'uploadedBy']))
            ->columns([
                TextColumn::make('step.label')->label('Step')->placeholder('—')->wrap()->searchable(),
                TextColumn::make('mime')->label('Type')->toggleable(),
                TextColumn::make('size')
                    ->label('Size')
                    ->state(function (ServiceProvisioningMedia $record): string {
                        $bytes = (int) ($record->size ?? 0);
                        if ($bytes < 1024) {
                            return $bytes.' B';
                        }

                        return number_format($bytes / 1024, 1).' KB';
                    }),
                TextColumn::make('uploaded_by')
                    ->label('Uploaded by')
                    ->state(function (ServiceProvisioningMedia $record): string {
                        $actor = $record->uploadedBy;
                        if (! $actor) {
                            return '—';
                        }

                        return $actor->name ?? class_basename($actor::class).' #'.$actor->getKey();
                    }),
                TextColumn::make('created_at')->label('Uploaded')->dateTime()->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                Action::make('download')
                    ->label('Download')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->action(function (ServiceProvisioningMedia $record): StreamedResponse {
                        $disk = $record->disk ?: 'local';
                        abort_unless(Storage::disk($disk)->exists($record->path), 404);

                        return Storage::disk($disk)->download(
                            $record->path,
                            $record->original_name ?: basename($record->path),
                        );
                    }),
            ])
            ->headerActions([])
            ->toolbarActions([])
            ->emptyStateHeading('No attachments yet')
            ->emptyStateDescription('Attachments added when completing steps appear here.');
    }
}
