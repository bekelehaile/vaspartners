<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TicketComment;
use App\Services\TicketCommentService;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TicketCommentAttachmentController extends Controller
{
    /** Inline open in browser — authenticated Filament staff only. */
    public function open(TicketComment $comment, TicketCommentService $comments): BinaryFileResponse
    {
        abort_unless(auth()->check(), 403);

        $ticket = $comment->ticket()->first();
        abort_unless($ticket, 404);
        $this->authorize('view', $ticket);
        abort_unless($comments->attachmentExists($comment), 404);

        $disk = $comment->attachment_disk ?: 'local';
        $path = (string) $comment->attachment_path;
        abort_unless(Storage::disk($disk)->exists($path), 404);

        $absolute = Storage::disk($disk)->path($path);
        $filename = $comment->attachment_original_name ?: basename($path);
        $mime = $comment->attachment_mime ?: (mime_content_type($absolute) ?: 'application/octet-stream');

        return response()->file($absolute, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="'.$this->safeFilename($filename).'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    protected function safeFilename(string $name): string
    {
        return str_replace(['"', "\r", "\n"], '', basename($name));
    }
}
