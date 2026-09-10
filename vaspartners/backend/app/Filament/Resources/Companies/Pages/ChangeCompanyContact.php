<?php

namespace App\Filament\Resources\Companies\Pages;

use App\Filament\Resources\Companies\CompanyResource;
use App\Models\Company;
use App\Models\Contact;
use App\Services\CompanyMembershipService;
use App\Services\ContactIdentityService;
use App\Support\PhoneNumber;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
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

    protected static ?string $navigationLabel = 'Change contact';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public function mount(int | string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->mountCanAuthorizeAccess();

        /** @var Company $company */
        $company = $this->getRecord();
        $owner = $company->ownerContact();

        $this->form->fill([
            'mode' => 'existing',
            'contact_id' => null,
            'phone' => null,
            'preview' => null,
            'current_owner' => $owner
                ? trim(($owner->name ?: 'Owner').' · '.($owner->phone_number ?: '—'))
                : 'No owner',
            'current_claim' => $company->claimPhone() ?: '—',
            'company_label' => trim($company->name.' · TIN '.($company->tin ?: '—')),
        ]);
    }

    public static function canAccess(array $parameters = []): bool
    {
        $record = $parameters['record'] ?? null;
        if (! $record instanceof Company) {
            $record = static::getResource()::resolveRecordRouteBinding($record);
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

    public function getSubheading(): ?string
    {
        return 'Pick an existing partner or enter a phone, then save. Old owner is disabled on this company only. Revenue and ERCA phones stay unchanged.';
    }

    public function getBreadcrumb(): string
    {
        return 'Change contact';
    }

    /**
     * @return array<string>
     */
    public function getBreadcrumbs(): array
    {
        /** @var Company $company */
        $company = $this->getRecord();

        return [
            CompanyResource::getUrl() => 'Companies',
            CompanyResource::getUrl('view', ['record' => $company]) => $company->name,
            'Change contact',
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Current')
                    ->schema([
                        Placeholder::make('company_label')
                            ->label('Company')
                            ->content(fn (Get $get): string => (string) ($get('company_label') ?: '—')),
                        Placeholder::make('current_owner')
                            ->label('Current owner')
                            ->content(fn (Get $get): string => (string) ($get('current_owner') ?: '—')),
                        Placeholder::make('current_claim')
                            ->label('Claim phone')
                            ->content(fn (Get $get): string => (string) ($get('current_claim') ?: '—')),
                    ])
                    ->columns(3),
                Section::make('New contact')
                    ->schema([
                        Radio::make('mode')
                            ->label('How to choose')
                            ->options([
                                'existing' => 'Pick existing contact',
                                'phone' => 'Enter phone number',
                            ])
                            ->inline()
                            ->live()
                            ->required()
                            ->afterStateUpdated(function (Set $set): void {
                                $set('contact_id', null);
                                $set('phone', null);
                                $set('preview', null);
                            }),
                        Select::make('contact_id')
                            ->label('Existing contact')
                            ->searchable()
                            ->preload(false)
                            ->native(false)
                            ->visible(fn (Get $get): bool => $get('mode') === 'existing')
                            ->required(fn (Get $get): bool => $get('mode') === 'existing')
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
                                if (! $c) {
                                    return null;
                                }

                                return trim(($c->name ?: 'Partner').' · '.($c->phone_number ?: '—'));
                            })
                            ->live()
                            ->afterStateUpdated(function ($state, Set $set): void {
                                $c = Contact::query()->find($state);
                                if (! $c) {
                                    $set('phone', null);
                                    $set('preview', null);

                                    return;
                                }
                                $set('phone', $c->phone_number);
                                $set('preview', 'Will assign: '.trim(($c->name ?: 'Partner').' · '.($c->phone_number ?: '—')));
                            })
                            ->helperText('Search by name or phone. Uses the real contact already in the system.')
                            ->columnSpanFull(),
                        TextInput::make('phone')
                            ->label('Phone number')
                            ->tel()
                            ->maxLength(32)
                            ->visible(fn (Get $get): bool => $get('mode') === 'phone')
                            ->required(fn (Get $get): bool => $get('mode') === 'phone')
                            ->live(onBlur: true)
                            ->afterStateUpdated(function ($state, Set $set): void {
                                $normalized = PhoneNumber::normalizeNullable((string) ($state ?? ''));
                                if (! filled($normalized) || ! PhoneNumber::isValidEthioTelecomMobile($normalized)) {
                                    $set('preview', 'Enter a valid Ethio telecom mobile number.');

                                    return;
                                }

                                $existing = Contact::query()->where('phone_number', $normalized)->first();
                                if ($existing) {
                                    $set('preview', 'Existing contact: '.trim(($existing->name ?: 'Partner').' · '.$normalized));

                                    return;
                                }

                                $crm = app(ContactIdentityService::class)->previewCrmPhone($normalized);
                                if ($crm['found']) {
                                    $set('preview', 'CRM match: '.$crm['name'].' · '.$normalized.' (will be created on save)');

                                    return;
                                }

                                $set('preview', $crm['message'] ?: 'Not found in contacts or CRM.');
                            })
                            ->dehydrateStateUsing(fn (?string $state): ?string => PhoneNumber::normalizeNullable($state))
                            ->helperText('If the phone is new, it must exist in CRM so a contact can be created.')
                            ->columnSpanFull(),
                        Placeholder::make('preview')
                            ->label('Preview')
                            ->content(fn (Get $get): string => (string) ($get('preview') ?: 'Choose a contact or enter a phone.')),
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
                                ->label('Save contact change')
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
        $data = $this->form->getState();
        $mode = (string) ($data['mode'] ?? 'existing');

        $phone = null;
        if ($mode === 'existing') {
            $contact = Contact::query()->find($data['contact_id'] ?? null);
            if (! $contact || ! filled($contact->phone_number)) {
                Notification::make()
                    ->title('Select a contact with a phone number')
                    ->danger()
                    ->send();

                return;
            }
            $phone = (string) $contact->phone_number;
        } else {
            $phone = (string) ($data['phone'] ?? '');
        }

        try {
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
            Notification::make()
                ->title('Could not change contact')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }
}
