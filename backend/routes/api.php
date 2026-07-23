<?php

use App\Http\Controllers\Api\V1\ClientPortalController;
use App\Http\Controllers\Api\V1\FaydaAuthController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\WebsiteContentController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('auth/fayda/redirect', [FaydaAuthController::class, 'redirect']);
    Route::get('auth/fayda/callback', [FaydaAuthController::class, 'callback']);

    Route::get('services', [ClientPortalController::class, 'services']);
    Route::get('document-requirements', [ClientPortalController::class, 'documentRequirements']);
    Route::get('faqs', [WebsiteContentController::class, 'faqs']);
    Route::get('blog-posts', [WebsiteContentController::class, 'blogPosts']);
    Route::get('blog-posts/{slug}', [WebsiteContentController::class, 'blogPost']);
    Route::get('gallery', [WebsiteContentController::class, 'gallery']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('auth/me', [FaydaAuthController::class, 'me']);
        Route::post('auth/logout', [FaydaAuthController::class, 'logout']);
        Route::post('profile/company', [ClientPortalController::class, 'completeCompanyProfile']);
        Route::get('profile/company/lookup', [ClientPortalController::class, 'lookupCompany']);
        Route::post('profile/company/attach', [ClientPortalController::class, 'requestAttachCompany']);
        Route::post('profile/company/detach', [ClientPortalController::class, 'requestDetachCompany']);

        Route::get('notifications', [NotificationController::class, 'index']);
        Route::post('notifications/read-all', [NotificationController::class, 'markAllRead']);
        Route::post('notifications/{id}/read', [NotificationController::class, 'markRead']);

        Route::get('tickets', [ClientPortalController::class, 'tickets']);
        Route::post('tickets', [ClientPortalController::class, 'storeTicket']);
        Route::get('tickets/{ticket}', [ClientPortalController::class, 'showTicket']);
        Route::post('tickets/{ticket}/documents', [ClientPortalController::class, 'uploadDocument']);
        Route::delete('tickets/{ticket}/documents/{document}', [ClientPortalController::class, 'deleteDocument']);
        Route::post('tickets/{ticket}/comments', [ClientPortalController::class, 'comment']);
        Route::get('tickets/{ticket}/comments/{comment}/attachment', [ClientPortalController::class, 'downloadCommentAttachment']);
        Route::get('subscriptions', [ClientPortalController::class, 'subscriptions']);
    });
});
