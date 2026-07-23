<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\CompanyMembershipService;
use App\Services\EsignetService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class FaydaAuthController extends Controller
{
    public function redirect(Request $request, EsignetService $esignet)
    {
        $built = $esignet->buildAuthorizationUrl();
        if (($built['status'] ?? '') !== 'ok') {
            return response()->json(['message' => $built['message'] ?? 'Unable to start Fayda login'], 500);
        }

        // After Fayda returns to FRONTEND /callback (registered redirect), it forwards here.
        // Final SPA landing stays /auth/callback with the Sanctum token.
        Cache::put('fayda_pkce:'.$built['state'], [
            'code_verifier' => $built['code_verifier'],
            'frontend_redirect' => config('vas.frontend_url').'/auth/callback',
        ], now()->addMinutes(15));

        return redirect()->away($built['auth_url']);
    }

    public function callback(Request $request, EsignetService $esignet)
    {
        $state = (string) $request->query('state');
        $code = (string) $request->query('code');
        $pkce = Cache::pull('fayda_pkce:'.$state);

        $frontend = config('vas.frontend_url').'/auth/callback';
        if (! $pkce || ! $code) {
            return redirect()->away($frontend.'?error=invalid_state');
        }

        $token = $esignet->exchangeCodeForToken($code, $pkce['code_verifier']);
        if (($token['status'] ?? '') !== 'ok') {
            return redirect()->away($frontend.'?error=token_exchange');
        }

        $info = $esignet->getUserInfo($token['token']['access_token']);
        if (($info['status'] ?? '') !== 'ok') {
            $reason = urlencode((string) ($info['message'] ?? 'userinfo'));

            return redirect()->away($frontend.'?error='.$reason);
        }

        $customer = $info['customer'];
        if ($customer->is_banned || ! $customer->is_active) {
            return redirect()->away($frontend.'?error=banned');
        }

        $accessToken = $customer->createToken('fayda')->plainTextToken;
        $target = $pkce['frontend_redirect'] ?? $frontend;
        $sep = str_contains($target, '?') ? '&' : '?';

        return redirect()->away($target.$sep.http_build_query([
            'token' => $accessToken,
            'customer_id' => $customer->public_id,
        ]));
    }

    public function me(Request $request, CompanyMembershipService $membership)
    {
        return response()->json([
            'data' => $membership->serializeCustomer($request->user()),
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out']);
    }
}
