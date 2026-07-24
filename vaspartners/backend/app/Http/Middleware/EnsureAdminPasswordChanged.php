<?php

namespace App\Http\Middleware;

use App\Filament\Pages\Auth\ForcePasswordChange;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminPasswordChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Filament::auth()->user();

        if (! $user || ! ($user->must_change_password ?? false)) {
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
