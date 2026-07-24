<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

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

        // Staging sits behind Docker nginx TLS (:8443); honour X-Forwarded-*.
        $middleware->trustProxies(
            at: env('TRUSTED_PROXIES', '*') === '*'
                ? '*'
                : array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', '*'))))),
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
