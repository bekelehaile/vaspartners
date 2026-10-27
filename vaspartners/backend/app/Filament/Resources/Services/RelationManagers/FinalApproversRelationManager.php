<?php

namespace App\Filament\Resources\Services\RelationManagers;

use App\Models\Service;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class FinalApproversRelationManager extends RelationManager
{
    protected static string $relationship = 'finalApprovers';

    protected static ?string $title = 'Final approvers (optional)';

    public function form(Schema $schema): Schema
    {
        /** @var Service $service */
        $service = $this->getOwnerRecord();

        return $schema->components([
            Select::make('requisition_id')
                ->label('Request type')
                ->options(fn (): array => $service->requisitions()
                    ->where('creates_subscription', true)
                    ->orderBy('name')
                    ->pluck('name', 'requisitions.id')
                    ->all())
                ->required()
                ->searchable()
                ->preload()
                ->helperText('Optional. After-sales types never use final approval.'),
            Select::make('user_id')
                ->label('Final approver')
                ->options(fn (): array => User::query()
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all())
                ->required()
                ->searchable()
                ->preload()
                ->helperText('If set, this person must approve before the AM can close. If not set, the AM closes after documents.'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->whereHas(
                'requisition',
                fn (Builder $q) => $q->where('creates_subscription', true),
            ))
            ->defaultSort('created_at', 'desc')
            ->searchable()
            ->description('Optional per request type. Configured → approval chain. Not configured → AM closes after docs.')
            ->columns([
                TextColumn::make('requisition.name')
                    ->label('Request type')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('user.name')
                    ->label('Final approver')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('user.is_active')
                    ->label('Active')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state ? 'Yes' : 'No')
                    ->color(fn ($state): string => $state ? 'success' : 'danger'),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('requisition_id')
                    ->label('Request type')
                    ->relationship(
                        'requisition',
                        'name',
                        fn (Builder $query) => $query->where('creates_subscription', true)->orderBy('name'),
                    )
                    ->searchable()
                    ->preload(),
                SelectFilter::make('user_id')
                    ->label('Final approver')
                    ->relationship(
                        'user',
                        'name',
                        fn (Builder $query) => $query->where('is_active', true)->orderBy('name'),
                    )
                    ->searchable()
                    ->preload(),
            ])
            ->headerActions([
                \Filament\Actions\CreateAction::make()
                    ->label('Add final approver'),
            ])
            ->recordActions([
                \Filament\Actions\EditAction::make(),
                \Filament\Actions\DeleteAction::make(),
            ])
            ->emptyStateHeading('No final approvers configured')
            ->emptyStateDescription('Leave empty for AM-only close after docs, or add a person to require their approval first.');
    }
}
