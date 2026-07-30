<?php

use App\Http\Controllers\Api\V1\ContactPortalController;
use App\Http\Controllers\Api\V1\FaydaAuthController;
use App\Http\Controllers\Api\V1\FeedbackController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\PortalAuthController;
use App\Http\Controllers\Api\V1\RevenuePortalController;
use App\Http\Controllers\Api\V1\TaxpayerController;
use App\Http\Controllers\Api\V1\WebsiteContentController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('auth/config', [PortalAuthController::class, 'config']);
    Route::post('auth/otp/request', [PortalAuthController::class, 'requestOtp'])
        ->middleware('throttle:portal-otp-request');
    Route::post('auth/otp/verify', [PortalAuthController::class, 'verifyOtp'])
        ->middleware('throttle:portal-otp-verify');

    Route::get('auth/fayda/redirect', [FaydaAuthController::class, 'redirect']);
    Route::get('auth/fayda/callback', [FaydaAuthController::class, 'callback']);

    Route::get('services', [ContactPortalController::class, 'services']);
    Route::get('groups', [ContactPortalController::class, 'groups']);
    Route::get('document-requirements', [ContactPortalController::class, 'documentRequirements']);
    Route::get('faqs', [WebsiteContentController::class, 'faqs']);
    Route::get('blog-posts', [WebsiteContentController::class, 'blogPosts']);
    Route::get('blog-posts/{slug}', [WebsiteContentController::class, 'blogPost']);
    Route::get('gallery', [WebsiteContentController::class, 'gallery']);

    // National TIN / company registry lookup (eTrade). Throttled; auth required.
    Route::get('taxpayers/tin/{tin}', [TaxpayerController::class, 'show'])
        ->middleware(['auth:sanctum', 'throttle:portal-tin-lookup']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('auth/me', [FaydaAuthController::class, 'me']);
        Route::post('auth/logout', [FaydaAuthController::class, 'logout']);
        Route::post('auth/identity/consent', [PortalAuthController::class, 'identityConsent'])
            ->middleware('throttle:portal-otp-verify');
        Route::post('profile/company', [ContactPortalController::class, 'completeCompanyProfile']);
        Route::post('profile/company/erca/preview', [ContactPortalController::class, 'previewErcaCompany'])
            ->middleware('throttle:portal-tin-lookup');
        Route::post('profile/company/erca/create', [ContactPortalController::class, 'createCompanyFromErcaConsent'])
            ->middleware('throttle:portal-tin-lookup');
        Route::post('profile/company/erca/update-tin', [ContactPortalController::class, 'updateCompanyTinFromErca'])
            ->middleware('throttle:portal-tin-lookup');
        Route::post('profile/company/erca/decline', [ContactPortalController::class, 'declineErcaCompany'])
            ->middleware('throttle:portal-tin-lookup');
        // TIN NUMBER submit — accept POST (portal) and PUT/PATCH for clients that use update verbs.
        Route::match(['post', 'put', 'patch'], 'profile/company/tin', [ContactPortalController::class, 'submitCompanyTin']);
        Route::post('profile/company/tin/name-consent', [ContactPortalController::class, 'applyErcaNameConsent'])
            ->middleware('throttle:portal-tin-lookup');
        Route::get('profile/company/lookup', [ContactPortalController::class, 'lookupCompany']);
        Route::post('profile/company/attach', [ContactPortalController::class, 'requestAttachCompany']);
        Route::post('profile/company/detach', [ContactPortalController::class, 'requestDetachCompany']);
        Route::post('profile/company/switch', [ContactPortalController::class, 'switchCompany']);
        Route::get('profile/company/members', [ContactPortalController::class, 'companyMembers']);
        Route::post('profile/company/members', [ContactPortalController::class, 'createCompanyMember']);
        Route::post('profile/company/members/{member}/enable', [ContactPortalController::class, 'enableCompanyMember']);
        Route::post('profile/company/members/{member}/disable', [ContactPortalController::class, 'disableCompanyMember']);
        Route::put('profile/company/members/{member}/permissions', [ContactPortalController::class, 'updateCompanyMemberPermissions']);
        Route::put('profile/company/members/{member}/phone', [ContactPortalController::class, 'updateCompanyMemberPhone']);
        Route::post('profile/company/transfer-ownership', [ContactPortalController::class, 'requestTransferOwnership']);
        Route::get('profile/company/membership-requests', [ContactPortalController::class, 'membershipRequests']);
        Route::get('profile/company/requests', [ContactPortalController::class, 'companyRequestsInbox']);
        Route::post('profile/company/requests/{changeRequest}/cancel', [ContactPortalController::class, 'cancelCompanyRequest']);
        Route::post('profile/company/membership-requests/{changeRequest}/approve', [ContactPortalController::class, 'approveMembershipRequest']);
        Route::post('profile/company/membership-requests/{changeRequest}/reject', [ContactPortalController::class, 'rejectMembershipRequest']);

        Route::get('notifications', [NotificationController::class, 'index']);
        Route::post('notifications/read-all', [NotificationController::class, 'markAllRead']);
        Route::delete('notifications', [NotificationController::class, 'clearAll']);
        Route::post('notifications/{id}/read', [NotificationController::class, 'markRead']);
        Route::delete('notifications/{id}', [NotificationController::class, 'destroy']);

        Route::get('tickets', [ContactPortalController::class, 'tickets']);
        Route::post('tickets', [ContactPortalController::class, 'storeTicket'])
            ->middleware('throttle:portal-ticket-create');
        Route::get('tickets/{ticket}', [ContactPortalController::class, 'showTicket']);
        Route::put('tickets/{ticket}', [ContactPortalController::class, 'updateTicket']);
        Route::delete('tickets/{ticket}', [ContactPortalController::class, 'destroyTicket']);
        Route::get('tickets/{ticket}/messages', [ContactPortalController::class, 'ticketMessages']);
        Route::post('tickets/{ticket}/documents', [ContactPortalController::class, 'uploadDocument']);
        Route::get('tickets/{ticket}/documents/{document}/download', [ContactPortalController::class, 'downloadDocument']);
        Route::delete('tickets/{ticket}/documents/{document}', [ContactPortalController::class, 'deleteDocument']);
        Route::post('tickets/{ticket}/comments', [ContactPortalController::class, 'comment']);
        Route::get('tickets/{ticket}/comments/{comment}/attachment', [ContactPortalController::class, 'downloadCommentAttachment']);
        Route::get('subscriptions', [ContactPortalController::class, 'subscriptions']);
        Route::get('revenue', [RevenuePortalController::class, 'index']);

        Route::get('feedback', [FeedbackController::class, 'index']);
        Route::post('feedback', [FeedbackController::class, 'store']);
    });
});
