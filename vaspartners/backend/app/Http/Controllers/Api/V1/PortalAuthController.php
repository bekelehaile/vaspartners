<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Services\CompanyMembershipService;
use App\Services\PortalPhoneOtpService;
use App\Support\PortalProfileOptions;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;
use Throwable;

class PortalAuthController extends Controller
{
    public function config()
    {
        return response()->json([
            'data' => [
                ...AppSetting::authConfig(),
                'genders' => PortalProfileOptions::GENDERS,
                'nationalities' => PortalProfileOptions::nationalities(),
                'default_nationality' => PortalProfileOptions::DEFAULT_NATIONALITY,
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
            $status = str_contains(strtolower($e->getMessage()), 'too many') ? 429 : 422;

            return response()->json(['message' => $e->getMessage()], $status);
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
                'contact' => $membership->serializeContact($result['contact']),
            ],
        ]);
    }
}
