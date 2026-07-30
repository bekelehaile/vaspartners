<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Etrade\EtradeTinLookupService;
use App\Support\TinNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaxpayerController extends Controller
{
    /**
     * Check company / taxpayer info by Ethiopian TIN (proxies eTrade ERCA lookup).
     *
     * GET /api/v1/taxpayers/tin/{tin}
     */
    public function show(Request $request, string $tin, EtradeTinLookupService $lookup): JsonResponse
    {
        $normalized = TinNumber::normalize($tin);

        if (! TinNumber::isValid($normalized)) {
            return response()->json([
                'message' => TinNumber::message(),
                'data' => [
                    'found' => false,
                    'tin' => $normalized,
                ],
            ], 422);
        }

        if (! $lookup->enabled()) {
            return response()->json([
                'message' => 'TIN lookup is temporarily unavailable.',
                'data' => [
                    'found' => false,
                    'tin' => $normalized,
                ],
            ], 503);
        }

        $result = $lookup->lookup($normalized);

        if (! empty($result['raw']['unavailable'])) {
            return response()->json([
                'message' => 'Unable to reach the national TIN registry. Please try again shortly.',
                'data' => [
                    'found' => false,
                    'tin' => $normalized,
                ],
            ], 502);
        }

        if (! $result['found']) {
            return response()->json([
                'message' => 'No taxpayer found for this TIN.',
                'data' => $result,
            ], 404);
        }

        // Drop bulky raw payload unless explicitly requested.
        if (! $request->boolean('raw')) {
            unset($result['raw']);
        }

        return response()->json([
            'message' => 'Taxpayer found.',
            'data' => $result,
        ]);
    }
}
