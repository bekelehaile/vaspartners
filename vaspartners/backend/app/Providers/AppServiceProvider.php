<?php

namespace App\Providers;

use App\Models\User;
use Filament\Tables\Table;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
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
        $this->configureSmsRateLimiters();
        $this->configurePortalOtpRateLimiters();

        // All admin tables: newest first, no clickable row navigation (use explicit actions).
        Table::configureUsing(function (Table $table): void {
            $table
                ->defaultSort('created_at', 'desc')
                ->recordUrl(null);
        });

        Event::listen(Login::class, function (Login $event): void {
            if ($event->user instanceof User) {
                $event->user->recordLogin();
            }
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

    private function configureSmsRateLimiters(): void
    {
        RateLimiter::for('sms-global', function () {
            $max = max(1, (int) config('notifications.sms_rate.global.max', 120));
            $decay = max(1, (int) config('notifications.sms_rate.global.decay_seconds', 60));

            return Limit::perMinutes(max(1, (int) ceil($decay / 60)), $max)->by('sms-global');
        });
    }

    private function configurePortalOtpRateLimiters(): void
    {
        // IP caps on top of per-phone limits inside PortalPhoneOtpService.
        RateLimiter::for('portal-otp-request', function ($request) {
            return Limit::perMinutes(5, 10)->by('portal-otp-req:'.$request->ip());
        });

        RateLimiter::for('portal-otp-verify', function ($request) {
            return Limit::perMinutes(5, 30)->by('portal-otp-ver:'.$request->ip());
        });
    }
}
