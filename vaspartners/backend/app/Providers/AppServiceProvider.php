<?php

namespace App\Providers;

use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use STS\FilamentImpersonate\Facades\Impersonation;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // All admin tables: newest first, no clickable row navigation (use explicit actions).
        Table::configureUsing(function (Table $table): void {
            $table
                ->defaultSort('created_at', 'desc')
                ->recordUrl(null);
        });

        // Filament Shield / Spatie: allow super_admin everything once roles exist
        Gate::before(function ($user, string $ability) {
            return method_exists($user, 'hasRole') && $user->hasRole('super_admin') ? true : null;
        });

        // Package leave defaults to `/` (Next.js). Always return to the admin panel.
        $this->app->booted(function () {
            Route::middleware(config('filament-impersonate.leave_middleware', 'web'))
                ->get('filament-impersonate/leave', function () {
                    $fallback = filament()->getCurrentOrDefaultPanel()?->getUrl() ?? '/admin';

                    if (! Impersonation::isImpersonating()) {
                        return redirect($fallback);
                    }

                    Impersonation::leave();

                    return redirect(session()->pull('impersonate.back_to') ?? $fallback);
                })
                ->name('filament-impersonate.leave');
        });
    }
}
