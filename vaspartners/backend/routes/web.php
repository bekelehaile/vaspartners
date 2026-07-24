<?php

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
        Route::get('ticket-comments/{comment}/attachment', [TicketCommentAttachmentController::class, 'download'])
            ->name('filament.admin.ticket-comments.attachment');
    });
