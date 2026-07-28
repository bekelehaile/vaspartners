<?php

namespace App\Filament\Pages;

use App\Models\RevenueServiceManager;
use App\Models\Service;
use App\Models\User;
use App\Support\RevenueCatalogServices;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;

/**
 * Assign account managers to existing catalog services for revenue scoping.
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
        foreach (RevenueCatalogServices::query() as $service) {
            $fill[(string) $service->id] = RevenueServiceManager::query()
                ->where('service_id', $service->id)
                ->pluck('user_id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }
        $this->form->fill($fill);
    }

    public function getSubheading(): ?string
    {
        return 'Assign account managers to existing catalog services. They only see revenue partners and monthly imports for those services. Super admin and admin see everything.';
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
        foreach (RevenueCatalogServices::query() as $service) {
            $sections[] = Section::make($service->name)
                ->description('Account managers who validate revenue for this catalog service.')
                ->schema([
                    Select::make((string) $service->id)
                        ->label('Account managers')
                        ->multiple()
                        ->options($managers)
                        ->searchable()
                        ->preload()
                        ->helperText('Leave empty only while onboarding; AMs with no service see no revenue data.'),
                ])
                ->collapsed();
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
        $serviceIds = Service::query()->where('is_active', true)->pluck('id')->map(fn ($id) => (int) $id)->all();

        DB::transaction(function () use ($data, $serviceIds): void {
            foreach ($serviceIds as $serviceId) {
                $userIds = collect($data[(string) $serviceId] ?? [])
                    ->map(fn ($id) => (int) $id)
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                RevenueServiceManager::query()
                    ->where('service_id', $serviceId)
                    ->whereNotIn('user_id', $userIds ?: [0])
                    ->delete();

                foreach ($userIds as $userId) {
                    RevenueServiceManager::query()->firstOrCreate([
                        'service_id' => $serviceId,
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
