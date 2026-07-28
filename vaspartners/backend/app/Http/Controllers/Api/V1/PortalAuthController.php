<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Services\CompanyMembershipService;
use App\Services\PortalPhoneOtpService;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

class PortalAuthController extends Controller
{
    public function config()
    {
        return response()->json([
            'data' => AppSetting::authConfig(),
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
            return response()->json(['message' => $e->getMessage()], 422);
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
        ]);

        try {
            $result = $otp->verify(
                $data['phone'],
                $data['code'],
                $data['name'] ?? null,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Unable to verify code. Please try again.'], 500);
        }

        return response()->json([
            'message' => 'Signed in.',
            'data' => [
                'token' => $result['token'],
                'is_new' => $result['is_new'],
                'contact' => $membership->serializeContact($result['contact']),
            ],
        ]);
    }
}
