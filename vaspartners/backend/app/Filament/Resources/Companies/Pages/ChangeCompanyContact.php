<?php

namespace App\Filament\Resources\Companies\Pages;

use App\Filament\Resources\Companies\CompanyResource;
use App\Models\Company;
use App\Models\Contact;
use App\Services\CompanyMembershipService;
use App\Services\ContactIdentityService;
use App\Support\PhoneNumber;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Dedicated admin GUI to replace a company's owner / claim contact.
 *
 * @property-read Schema $form
 */
class ChangeCompanyContact extends Page
{
    use InteractsWithRecord;

    protected static string $resource = CompanyResource::class;

    protected static ?string $title = 'Change contact';

    protected static bool $shouldRegisterNavigation = false;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public function mount(int | string $record): void
    {
        $this->record = $this->resolveRecord($record);

        /** @var Company $company */
        $company = $this->getRecord();

        abort_unless(
            (bool) $company->erca_tin_verified && CompanyResource::canEdit($company),
            403,
        );

        $owner = $company->ownerContact();

        $this->form->fill([
            'current_owner' => $owner
                ? trim(($owner->name ?: 'Owner').' / '.($owner->phone_number ?: '—'))
                : 'No owner',
            'current_claim' => $company->claimPhone() ?: '—',
            'contact_id' => null,
            'phone' => '',
        ]);
    }

    public static function canAccess(array $parameters = []): bool
    {
        $record = $parameters['record'] ?? null;

        if (! $record instanceof Company) {
            if (! filled($record)) {
                return false;
            }

            try {
                $record = static::getResource()::resolveRecordRouteBinding($record);
            } catch (Throwable) {
                return false;
            }
        }

        if (! $record instanceof Company) {
            return false;
        }

        return (bool) $record->erca_tin_verified && CompanyResource::canEdit($record);
    }

    public function getTitle(): string | Htmlable
    {
        /** @var Company $company */
        $company = $this->getRecord();

        return 'Change contact — '.$company->name;
    }

    public function getSubheading(): string | Htmlable | null
    {
        return 'Pick an existing partner or type a phone, then save. Old owner is disabled on this company only.';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Current contact')
                    ->schema([
                        TextInput::make('current_owner')
                            ->label('Owner')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('current_claim')
                            ->label('Claim phone')
                            ->disabled()
                            ->dehydrated(false),
                    ])
                    ->columns(2),
                Section::make('New contact')
                    ->schema([
                        Select::make('contact_id')
                            ->label('Existing contact')
                            ->searchable()
                            ->preload(false)
                            ->native(false)
                            ->getSearchResultsUsing(function (string $search): array {
                                $term = trim($search);
                                if (mb_strlen($term) < 2) {
                                    return [];
                                }

                                $digits = PhoneNumber::normalizeNullable($term);

                                return Contact::query()
                                    ->where('is_active', true)
                                    ->where(function ($q) use ($term, $digits): void {
                                        $q->where('name', 'ilike', '%'.$term.'%')
                                            ->orWhere('phone_number', 'ilike', '%'.$term.'%');
                                        if (filled($digits) && strlen($digits) >= 3) {
                                            $q->orWhere('phone_number', 'like', '%'.$digits.'%');
                                        }
                                    })
                                    ->orderBy('name')
                                    ->limit(40)
                                    ->get(['id', 'name', 'phone_number'])
                                    ->mapWithKeys(fn (Contact $c): array => [
                                        (string) $c->id => trim(($c->name ?: 'Partner').' · '.($c->phone_number ?: '—')),
                                    ])
                                    ->all();
                            })
                            ->getOptionLabelUsing(function ($value): ?string {
                                $c = Contact::query()->find($value);

                                return $c
                                    ? trim(($c->name ?: 'Partner').' · '.($c->phone_number ?: '—'))
                                    : null;
                            })
                            ->live()
                            ->afterStateUpdated(function ($state, Set $set): void {
                                $c = Contact::query()->find($state);
                                $set('phone', $c?->phone_number ?: '');
                            })
                            ->helperText('Search by name or phone. Optional if you enter a phone below.')
                            ->columnSpanFull(),
                        TextInput::make('phone')
                            ->label('Phone')
                            ->tel()
                            ->required()
                            ->maxLength(32)
                            ->helperText('Required. Existing contact phones save directly; new phones need a CRM match.')
                            ->dehydrateStateUsing(fn (?string $state): ?string => PhoneNumber::normalizeNullable($state))
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
                    ->livewireSubmitHandler('save')
                    ->footer([
                        Actions::make([
                            Action::make('save')
                                ->label('Save')
                                ->submit('save')
                                ->color('warning')
                                ->icon('heroicon-o-check'),
                            Action::make('cancel')
                                ->label('Cancel')
                                ->color('gray')
                                ->url(fn (): string => CompanyResource::getUrl('view', ['record' => $this->getRecord()])),
                        ])->alignment(Alignment::Start),
                    ]),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label('Back to company')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(fn (): string => CompanyResource::getUrl('view', ['record' => $this->getRecord()])),
        ];
    }

    public function save(CompanyMembershipService $membership): void
    {
        /** @var Company $company */
        $company = $this->getRecord();

        try {
            $data = $this->form->getState();
            $phone = (string) ($data['phone'] ?? '');

            if ($phone === '' && filled($data['contact_id'] ?? null)) {
                $contact = Contact::query()->find($data['contact_id']);
                $phone = (string) ($contact?->phone_number ?? '');
            }

            $membership->adminChangeCompanyContact(
                $company,
                $phone,
                auth()->user(),
            );

            Notification::make()
                ->title('Contact saved')
                ->success()
                ->send();

            $this->redirect(CompanyResource::getUrl('view', ['record' => $company->fresh() ?? $company]));
        } catch (ValidationException $e) {
            Notification::make()
                ->title('Could not change contact')
                ->body(collect($e->errors())->flatten()->first() ?: $e->getMessage())
                ->danger()
                ->send();
        } catch (Throwable $e) {
            report($e);
            Notification::make()
                ->title('Could not change contact')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }
}
