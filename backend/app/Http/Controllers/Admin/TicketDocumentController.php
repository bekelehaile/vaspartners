<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TicketDocument;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TicketDocumentController extends Controller
{
    /** Inline open (view in browser) — authenticated Filament staff only. */
    public function open(TicketDocument $document): BinaryFileResponse
    {
        $this->authorizeDocument($document);

        $disk = $document->disk ?: 'local';
        abort_unless($document->path && Storage::disk($disk)->exists($document->path), 404);

        $absolute = Storage::disk($disk)->path($document->path);
        $filename = $document->original_name ?: basename($document->path);
        $mime = $document->mime_type ?: (mime_content_type($absolute) ?: 'application/octet-stream');

        return response()->file($absolute, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="'.$this->safeFilename($filename).'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Force download — authenticated Filament staff only. */
    public function download(TicketDocument $document): BinaryFileResponse|\Symfony\Component\HttpFoundation\StreamedResponse
    {
        $this->authorizeDocument($document);

        $disk = $document->disk ?: 'local';
        abort_unless($document->path && Storage::disk($disk)->exists($document->path), 404);

        return Storage::disk($disk)->download(
            $document->path,
            $document->original_name ?: basename($document->path),
        );
    }

    protected function authorizeDocument(TicketDocument $document): void
    {
        abort_unless(auth()->check(), 403);

        $ticket = $document->ticket()->first();
        abort_unless($ticket, 404);
        $this->authorize('view', $ticket);
    }

    protected function safeFilename(string $name): string
    {
        return str_replace(['"', "\r", "\n"], '', basename($name));
    }
}
