<?php

use App\Http\Controllers\Admin\ServiceProvisioningMessageAttachmentController;
use App\Http\Controllers\Admin\TicketCommentAttachmentController;
use App\Http\Controllers\Admin\TicketDocumentController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware(['web', 'auth'])
    ->prefix('admin')
    ->group(function () {
        Route::get('ticket-documents/{document}/open', [TicketDocumentController::class, 'open'])
            ->name('filament.admin.ticket-documents.open');
        Route::get('ticket-documents/{document}/download', [TicketDocumentController::class, 'download'])
            ->name('filament.admin.ticket-documents.download');
        Route::get('ticket-comments/{comment}/attachment', [TicketCommentAttachmentController::class, 'open'])
            ->name('filament.admin.ticket-comments.attachment');
        Route::get('provisioning-messages/{message}/attachment', [ServiceProvisioningMessageAttachmentController::class, 'open'])
            ->name('filament.admin.provisioning-messages.attachment');

        // Legacy Filament slug was /admin/tickets/{tt}; resource is now service-requests.
        // Keep redirects so older database notification "Open" links still work.
        Route::get('tickets/{tt}', function (string $tt) {
            return redirect('/admin/service-requests/'.$tt, 301);
        })->where('tt', '[A-Za-z0-9_-]+');
    });
