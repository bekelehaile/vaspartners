<?php

namespace App\Filament\Pages;

use App\Enums\RevenueServiceFamily;
use App\Models\RevenueFamilyManager;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;

/**
 * Assign 2+ account managers per revenue product family for validation scoping.
 *
 * @property-read Schema $form
 */
class ManageRevenueFamilyManagers extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|\UnitEnum|null $navigationGroup = 'Partners';

    protected static ?string $navigationLabel = 'Revenue AM assignment';

    protected static ?string $title = 'Revenue account managers';

    protected static ?int $navigationSort = 7;

    protected static ?string $slug = 'revenue-am-assignment';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->canAccessAllRevenue();
    }

    public function mount(): void
    {
        $fill = [];
        foreach (RevenueServiceFamily::cases() as $family) {
            $fill[$family->value] = RevenueFamilyManager::query()
                ->where('service_family', $family->value)
                ->pluck('user_id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }
        $this->form->fill($fill);
    }

    public function getSubheading(): ?string
    {
        return 'Assign account managers to each product line (API with MA, SMS-MO, Premium SMS MT, CRBT). They only see partners and monthly rows for their families. Super admin and admin see everything.';
    }

    public function form(Schema $schema): Schema
    {
        $managers = User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->whereIn('name', ['account_manager', 'admin']))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();

        $sections = [];
        foreach (RevenueServiceFamily::cases() as $family) {
            $sections[] = Section::make($family->label())
                ->description('Select two or more account managers who validate this sheet type.')
                ->schema([
                    Select::make($family->value)
                        ->label('Account managers')
                        ->multiple()
                        ->options($managers)
                        ->searchable()
                        ->preload()
                        ->required()
                        ->minItems(0)
                        ->helperText('Leave empty only while onboarding; AMs with no family see no revenue data.'),
                ]);
        }

        return $schema->components($sections)->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            \Filament\Schemas\Components\Form::make([
                \Filament\Schemas\Components\EmbeddedSchema::make('form'),
            ])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    \Filament\Schemas\Components\Actions::make([
                        \Filament\Actions\Action::make('save')
                            ->label('Save assignments')
                            ->submit('save')
                            ->color('primary')
                            ->icon('heroicon-o-check'),
                    ]),
                ]),
        ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();

        DB::transaction(function () use ($data): void {
            foreach (RevenueServiceFamily::cases() as $family) {
                $userIds = collect($data[$family->value] ?? [])
                    ->map(fn ($id) => (int) $id)
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                RevenueFamilyManager::query()
                    ->where('service_family', $family->value)
                    ->whereNotIn('user_id', $userIds ?: [0])
                    ->delete();

                foreach ($userIds as $userId) {
                    RevenueFamilyManager::query()->firstOrCreate([
                        'service_family' => $family->value,
                        'user_id' => $userId,
                    ]);
                }
            }
        });

        Notification::make()
            ->title('Revenue AM assignments saved')
            ->success()
            ->send();
    }
}
