<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Services\CompanyMembershipService;
use App\Services\ContactIdentityService;
use App\Services\PortalPhoneOtpService;
use App\Support\PortalProfileOptions;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class PortalAuthController extends Controller
{
    public function config()
    {
        return response()->json([
            'data' => [
                ...AppSetting::authConfig(),
                'erca_tin' => AppSetting::ercaTinConfig(),
                'genders' => PortalProfileOptions::GENDERS,
                'nationalities' => PortalProfileOptions::nationalities(),
                'default_nationality' => PortalProfileOptions::DEFAULT_NATIONALITY,
                'crm_identity_enabled' => (bool) config('services.crm.enabled', true),
            ],
        ]);
    }

    public function requestOtp(Request $request, PortalPhoneOtpService $otp)
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:32'],
        ]);

        try {
            $result = $otp->request($data['phone']);
        } catch (RuntimeException $e) {
            $message = $e->getMessage();
            $retryLater = str_contains(strtolower($message), 'too many')
                || str_contains(strtolower($message), 'wait')
                || str_contains(strtolower($message), 'try again in');
            $status = $retryLater ? 429 : 422;

            return response()->json([
                'message' => $retryLater
                    ? $message
                    : 'Unable to send verification code. Please try again.',
            ], $status);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Unable to send verification code. Please try again.'], 500);
        }

        return response()->json([
            'message' => 'Verification code sent.',
            'data' => $result,
        ]);
    }

    public function verifyOtp(Request $request, PortalPhoneOtpService $otp, CompanyMembershipService $membership)
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:32'],
            'code' => ['required', 'string', 'max:12'],
            'name' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'string', 'max:255'],
            'gender' => ['nullable', 'string', Rule::in(PortalProfileOptions::GENDERS)],
            'nationality' => ['nullable', 'string', Rule::in(PortalProfileOptions::nationalities())],
        ]);

        try {
            $result = $otp->verify(
                $data['phone'],
                $data['code'],
                [
                    'name' => $data['name'] ?? null,
                    'email' => $data['email'] ?? null,
                    'gender' => $data['gender'] ?? null,
                    'nationality' => $data['nationality'] ?? PortalProfileOptions::DEFAULT_NATIONALITY,
                ],
            );
        } catch (RuntimeException $e) {
            $status = str_contains(strtolower($e->getMessage()), 'too many') ? 429 : 422;

            return response()->json(['message' => $e->getMessage()], $status);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Unable to verify code. Please try again.'], 500);
        }

        return response()->json([
            'message' => 'Signed in.',
            'data' => [
                'token' => $result['token'],
                'is_new' => $result['is_new'],
                'expires_in' => $result['expires_in'],
                'identity' => $result['identity'],
                'contact' => $membership->serializeContact($result['contact']),
            ],
        ]);
    }

    /**
     * Accept or decline CRM identity proposal after OTP (or refresh pending proposal).
     */
    public function identityConsent(
        Request $request,
        ContactIdentityService $identity,
        CompanyMembershipService $membership,
    ) {
        $data = $request->validate([
            'action' => ['required', 'string', 'in:accept,decline,refresh'],
            'name' => ['nullable', 'string', 'max:120'],
        ]);

        /** @var \App\Models\Contact $contact */
        $contact = $request->user();

        try {
            if ($data['action'] === 'accept') {
                $contact = $identity->acceptCrmConsent($contact);
            } elseif ($data['action'] === 'decline') {
                $identity->declineCrmConsent($contact);
                if (filled($data['name'] ?? null)) {
                    $contact = $identity->updateManualName($contact, (string) $data['name']);
                }
            } else {
                $resolved = $identity->resolveAfterAuth($contact);

                return response()->json([
                    'message' => 'Identity status refreshed.',
                    'data' => [
                        'identity' => $resolved,
                        'contact' => $membership->serializeContact($contact->fresh(['company', 'memberships.company']) ?? $contact),
                    ],
                ]);
            }
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Unable to update identity. Please try again.'], 500);
        }

        $contact = $contact->fresh(['company', 'memberships.company']) ?? $contact;
        $pending = $identity->pendingProposal($contact);

        return response()->json([
            'message' => $data['action'] === 'accept'
                ? 'Identity verified with Ethio telecom CRM.'
                : 'CRM identity declined.',
            'data' => [
                'identity' => [
                    'needs_consent' => false,
                    'needs_manual_name' => $data['action'] === 'decline'
                        && blank(trim((string) $contact->name)),
                    'crm_available' => (bool) config('services.crm.enabled', true),
                    'proposal' => $pending,
                    'verified_via' => $contact->identity_verified_via,
                ],
                'contact' => $membership->serializeContact($contact),
            ],
        ]);
    }
}
