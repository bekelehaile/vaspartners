<?php

namespace App\Http\Middleware;

use App\Filament\Pages\Auth\ForcePasswordChange;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use STS\FilamentImpersonate\Facades\Impersonation;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminPasswordChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Filament::auth()->user();

        // Do not force a password change while impersonating — nearly all seeded
        // staff have must_change_password=true, which would trap the session.
        if (! $user || ! ($user->must_change_password ?? false) || Impersonation::isImpersonating()) {
            return $next($request);
        }

        $changeUrl = ForcePasswordChange::getUrl();
        $path = trim(parse_url($changeUrl, PHP_URL_PATH) ?: '/admin/force-password-change', '/');

        if ($request->is($path) || $request->is($path.'/*') || $request->routeIs('filament.*.auth.logout')) {
            return $next($request);
        }

        return redirect()->to($changeUrl);
    }
}
