<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Partner portal uses Bearer Sanctum tokens (not cookie SPA auth).
        // Do not enable statefulApi() — it enforces CSRF for SANCTUM_STATEFUL_DOMAINS
        // and breaks Next.js → Laravel API POSTs (e.g. company profile).

        // Avoid route('login') — that named route does not exist (Next.js owns /login).
        $middleware->redirectGuestsTo(fn (): string => '/login');

        // Staging sits behind Docker nginx TLS (:8443); honour X-Forwarded-*.
        $middleware->trustProxies(
            at: env('TRUSTED_PROXIES', '*') === '*'
                ? '*'
                : array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', '*'))))),
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API (incl. document download with Accept: octet-stream) must never 500
        // trying to redirect to a missing named login route.
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }

            return null;
        });

        // Last line of defence: DB unique index on companies.tin must never surface as a 500.
        $exceptions->render(function (UniqueConstraintViolationException $e) {
            if (! str_contains($e->getMessage(), 'companies_tin_unique')) {
                return null;
            }

            throw ValidationException::withMessages([
                'company_tin' => 'This TIN is already registered to another company. TIN numbers are unique — use “Join existing company”, or contact an administrator.',
                'tin' => 'This TIN is already registered to another company. TIN numbers are unique.',
            ]);
        });
    })
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('vas:scan-document-missing')
            ->hourly()
            ->withoutOverlapping(55)
            ->description('Reject open/in-progress requests missing required documents and SMS partners');

        $schedule->command('vas:scan-invalid-tin --notify-all')
            ->dailyAt('09:15')
            ->withoutOverlapping(120)
            ->description('Daily: clear false TIN approvals and SMS owners with invalid TIN');

        // Small ERCA batches only — never bulk-flood eTrade (limit/sleep in command + global cap).
        // Prefer unverified Has-owner companies first.
        $schedule->command('vas:scan-erca-tin --unverified-only')
            ->everyFiveMinutes()
            ->withoutOverlapping(4)
            ->description('Throttled ERCA TIN check for unverified Has-owner companies');

        $schedule->command('vas:notify-erca-mismatch')
            ->dailyAt('09:00')
            ->withoutOverlapping(120)
            ->description('Daily SMS: subscribed companies with ERCA name mismatch');

        $schedule->command('vas:open-due-renewals')
            ->dailyAt('01:00')
            ->description('Open renewal service requests for subscriptions in the renewal lead window');

        $schedule->command('vas:cleanup-orphan-contacts')
            ->dailyAt('02:30')
            ->description('Soft-delete contacts with no memberships, tickets, or subscriptions (7+ days old)');

        $schedule->command('sanctum:prune-expired --hours=24')
            ->dailyAt('03:15')
            ->description('Delete expired partner portal Sanctum tokens');
    })->create();
