<?php

namespace App\Filament\Resources\RevenueImports\Pages;

use App\Filament\Imports\MonthlyRevenueImporter;
use App\Filament\Resources\RevenueImports\RevenueImportResource;
use App\Models\RevenuePartner;
use App\Models\User;
use App\Services\RevenueImportService;
use App\Support\RevenueCatalogServices;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/**
 * Compose a monthly revenue import from the signed-in AM's Revenue Partners list.
 *
 * @property-read Schema $form
 */
class ComposeFromPartners extends Page
{
    protected static string $resource = RevenueImportResource::class;

    protected static ?string $title = 'From my partners';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'period' => now()->format('F Y'),
            'vas_service_id' => null,
            'message_template' => RevenueImportService::DEFAULT_SMS_TEMPLATE,
            'only_with_phone' => true,
            'lines' => [],
        ]);
    }

    public function getSubheading(): ?string
    {
        return 'Select partners, enter amounts, then review and send SMS.';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Import')->schema([
                    Select::make('period')
                        ->label('Month')
                        ->options(fn (): array => MonthlyRevenueImporter::monthOptions())
                        ->required()
                        ->searchable()
                        ->native(false),
                    Select::make('vas_service_id')
                        ->label('Catalog service')
                        ->options(fn (): array => RevenueCatalogServices::options())
                        ->required()
                        ->searchable()
                        ->preload()
                        ->native(false)
                        ->live()
                        ->afterStateUpdated(function (Set $set): void {
                            $set('lines', []);
                        }),
                    Textarea::make('message_template')
                        ->label('SMS message')
                        ->rows(4)
                        ->required()
                        ->maxLength(640)
                        ->helperText('{company_name}, {period}, {service_type}, {service_id}, {amount}')
                        ->columnSpanFull(),
                    Toggle::make('only_with_phone')
                        ->label('Only partners with a phone number')
                        ->default(true)
                        ->live()
                        ->afterStateUpdated(function (Set $set): void {
                            $set('lines', []);
                        }),
                ])->columns(2),
                Section::make('Partners & amounts')->schema([
                    Actions::make([
                        Action::make('load_all')
                            ->label('Load all partners for this service')
                            ->icon('heroicon-o-user-group')
                            ->color('gray')
                            ->action(function (Get $get, Set $set): void {
                                $vasServiceId = (int) ($get('vas_service_id') ?? 0);
                                if ($vasServiceId <= 0) {
                                    Notification::make()
                                        ->title('Select a catalog service first')
                                        ->warning()
                                        ->send();

                                    return;
                                }

                                $partners = $this->partnerQuery(
                                    $vasServiceId,
                                    (bool) ($get('only_with_phone') ?? true),
                                )->get();

                                $existingAmounts = collect(Arr::wrap($get('lines') ?? []))
                                    ->mapWithKeys(function ($line): array {
                                        $id = (int) ($line['revenue_partner_id'] ?? 0);

                                        return $id > 0 ? [$id => $line['amount'] ?? null] : [];
                                    });

                                $lines = $partners->map(fn (RevenuePartner $partner): array => [
                                    'revenue_partner_id' => $partner->id,
                                    'amount' => $existingAmounts->get($partner->id),
                                ])->values()->all();

                                $set('lines', $lines);

                                Notification::make()
                                    ->title($partners->isEmpty()
                                        ? 'No partners found'
                                        : 'Loaded '.$partners->count().' partner(s)')
                                    ->body($partners->isEmpty()
                                        ? 'Try another service, or add partners under Revenue partners.'
                                        : 'Enter an amount for each partner.')
                                    ->color($partners->isEmpty() ? 'warning' : 'success')
                                    ->send();
                            }),
                    ]),
                    Repeater::make('lines')
                        ->label('Partners')
                        ->schema([
                            Select::make('revenue_partner_id')
                                ->label('Partner')
                                ->options(function (Get $get): array {
                                    $vasServiceId = (int) ($get('../../vas_service_id') ?? $get('vas_service_id') ?? 0);
                                    if ($vasServiceId <= 0) {
                                        return [];
                                    }

                                    return $this->partnerQuery(
                                        $vasServiceId,
                                        (bool) ($get('../../only_with_phone') ?? $get('only_with_phone') ?? true),
                                    )
                                        ->get()
                                        ->mapWithKeys(function (RevenuePartner $partner): array {
                                            $key = $partner->service_id ?: $partner->short_code ?: '#'.$partner->id;
                                            $phone = $partner->phone ?: 'no phone';

                                            return [
                                                $partner->id => $key.' · '.$partner->partner_name.' · '.$phone,
                                            ];
                                        })
                                        ->all();
                                })
                                ->searchable()
                                ->required()
                                ->native(false)
                                ->columnSpan(2),
                            TextInput::make('amount')
                                ->label('Amount (ETB)')
                                ->numeric()
                                ->required()
                                ->rule('gt:0')
                                ->columnSpan(1),
                        ])
                        ->columns(3)
                        ->defaultItems(0)
                        ->addActionLabel('Add partner')
                        ->reorderable(false)
                        ->columnSpanFull(),
                ]),
            ])
            ->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([EmbeddedSchema::make('form')])
                    ->id('form')
                    ->livewireSubmitHandler('compose')
                    ->footer([
                        Actions::make([
                            Action::make('compose')
                                ->label('Create monthly revenue')
                                ->submit('compose')
                                ->color('primary')
                                ->icon('heroicon-o-banknotes'),
                            Action::make('cancel')
                                ->label('Cancel')
                                ->color('gray')
                                ->url(RevenueImportResource::getUrl('index')),
                        ])->alignment(Alignment::Start),
                    ]),
            ]);
    }

    public function compose(RevenueImportService $revenueImports): void
    {
        $data = $this->form->getState();
        /** @var User|null $actor */
        $actor = auth()->user();
        if (! $actor) {
            return;
        }

        try {
            $import = $revenueImports->createFromPartners(
                $actor,
                (string) ($data['period'] ?? ''),
                (int) ($data['vas_service_id'] ?? 0),
                (string) ($data['message_template'] ?? ''),
                Arr::wrap($data['lines'] ?? []),
            );

            Notification::make()
                ->title('Monthly revenue created from partners')
                ->body(sprintf(
                    'Ready %d · missing phone %d · total %d. Review then Send SMS.',
                    (int) $import->matched_count,
                    (int) $import->missing_phone_count,
                    (int) $import->total_count,
                ))
                ->success()
                ->send();

            $this->redirect(RevenueImportResource::getUrl('view', ['record' => $import]));
        } catch (ValidationException $e) {
            Notification::make()
                ->title('Could not create from partners')
                ->body(collect($e->errors())->flatten()->first() ?? $e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<RevenuePartner>
     */
    protected function partnerQuery(int $vasServiceId, bool $onlyWithPhone)
    {
        /** @var User|null $user */
        $user = auth()->user();

        $query = RevenuePartner::query()
            ->where('is_active', true)
            ->where('vas_service_id', $vasServiceId)
            ->orderBy('partner_name');

        if ($user && ! $user->canAccessAllRevenue()) {
            $query->where('created_by_user_id', $user->id);
        }

        if ($onlyWithPhone) {
            $query->whereNotNull('phone')
                ->where('phone', '!=', '')
                ->whereRaw("phone ~ '^[97][0-9]{8}$'");
        }

        return $query;
    }
}
