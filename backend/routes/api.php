<?php

use App\Http\Controllers\Api\V1\ClientPortalController;
use App\Http\Controllers\Api\V1\FaydaAuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('auth/fayda/redirect', [FaydaAuthController::class, 'redirect']);
    Route::get('auth/fayda/callback', [FaydaAuthController::class, 'callback']);

    Route::get('services', [ClientPortalController::class, 'services']);
    Route::get('document-requirements', [ClientPortalController::class, 'documentRequirements']);
    Route::get('faqs', [ClientPortalController::class, 'faqs']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('auth/me', [FaydaAuthController::class, 'me']);
        Route::post('auth/logout', [FaydaAuthController::class, 'logout']);
        Route::post('profile/company', [ClientPortalController::class, 'completeCompanyProfile']);

        Route::get('tickets', [ClientPortalController::class, 'tickets']);
        Route::post('tickets', [ClientPortalController::class, 'storeTicket']);
        Route::get('tickets/{ticket}', [ClientPortalController::class, 'showTicket']);
        Route::post('tickets/{ticket}/documents', [ClientPortalController::class, 'uploadDocument']);
        Route::post('tickets/{ticket}/comments', [ClientPortalController::class, 'comment']);
        Route::get('subscriptions', [ClientPortalController::class, 'subscriptions']);
    });
});
