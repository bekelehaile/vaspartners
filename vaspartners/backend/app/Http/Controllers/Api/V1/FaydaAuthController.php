<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Services\CompanyMembershipService;
use App\Services\EsignetService;
use App\Support\PortalAccessToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class FaydaAuthController extends Controller
{
    public function redirect(Request $request, EsignetService $esignet)
    {
        if (! AppSetting::faydaEnabled()) {
            return response()->json([
                'message' => 'Fayda sign-in is currently disabled. Use phone OTP instead.',
            ], 403);
        }

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

        $contact = $info['contact'];
        if (! $contact->is_active) {
            return redirect()->away($frontend.'?error=inactive');
        }

        // Deactivated companies do not block Fayda sign-in — VAS ops are gated per current company.
        $accessToken = PortalAccessToken::issue($contact, PortalAccessToken::NAME_FAYDA);
        $target = $pkce['frontend_redirect'] ?? $frontend;
        $sep = str_contains($target, '?') ? '&' : '?';

        return redirect()->away($target.$sep.http_build_query([
            'token' => $accessToken,
            'contact_id' => $contact->public_id,
            'expires_in' => PortalAccessToken::ttlMinutes() * 60,
        ]));
    }

    public function me(Request $request, CompanyMembershipService $membership, \App\Services\ContactIdentityService $identity)
    {
        $contact = $request->user();

        // Restore company context for members/owners with active memberships.
        $contact = $membership->ensureActiveCompanyContext($contact);

        // Unverified partners: (re)stage CRM consent when possible.
        $identityState = null;
        if (! $contact->isIdentityVerified()) {
            $identityState = $identity->resolveAfterAuth($contact);
            $contact = $contact->fresh(['company', 'memberships.company']) ?? $contact;
        }

        $payload = $membership->serializeContact($contact);
        if ($identityState !== null) {
            $payload['needs_identity_consent'] = (bool) ($identityState['needs_consent'] ?? false);
            $payload['needs_manual_name'] = (bool) ($identityState['needs_manual_name'] ?? false);
            $payload['identity_proposal'] = $identityState['proposal'] ?? null;
        }

        return response()->json([
            'data' => $payload,
        ]);
    }

    public function logout(Request $request)
    {
        $user = $request->user();

        // Revoke every portal token so the session cannot be reused.
        $user->tokens()->delete();

        return response()->json(['message' => 'Logged out']);
    }
}
