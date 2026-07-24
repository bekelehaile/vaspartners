<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\Enums\ThemeMode;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use App\Http\Middleware\EnsureAdminPasswordChanged;
use App\Filament\Pages\Auth\ForcePasswordChange;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login(\App\Filament\Pages\Auth\Login::class)
            ->passwordReset(
                \App\Filament\Pages\Auth\RequestPasswordReset::class,
                \App\Filament\Pages\Auth\ResetPassword::class,
            )
            ->colors([
                // Ethio telecom lemon / lime primary (aligned with portal --primary / #80CA28)
                'primary' => Color::hex('#80CA28'),
            ])
            ->defaultThemeMode(ThemeMode::Light)
            ->brandName('VAS Partners Admin')
            ->brandLogo(asset('brand/ethio_logo_full.png'))
            ->brandLogoHeight('2.75rem')
            ->favicon(asset('brand/etlogo.png'))
            ->globalSearch(false)
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                \App\Filament\Pages\Dashboard::class,
                ForcePasswordChange::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                EnsureAdminPasswordChanged::class,
            ])
            ->plugins([
                \BezhanSalleh\FilamentShield\FilamentShieldPlugin::make()
                    ->navigationGroup('User Management')
                    ->navigationLabel('Roles')
                    ->navigationSort(2),
            ])
            ->databaseNotifications()
            ->spa((bool) env('FILAMENT_SPA', false))
            ->sidebarCollapsibleOnDesktop()
            ->navigationGroups([
                NavigationGroup::make()->label('Tickets')->icon(Heroicon::Ticket),
                NavigationGroup::make()->label('Catalog')->icon(Heroicon::Cog6Tooth),
                NavigationGroup::make()->label('Partners')->icon(Heroicon::BuildingOffice),
                NavigationGroup::make()->label('User Management')->icon(Heroicon::UserGroup),
                NavigationGroup::make()->label('Geo')->icon(Heroicon::Map),
            ]);
    }
}
