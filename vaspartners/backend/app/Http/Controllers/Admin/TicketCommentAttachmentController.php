<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TicketComment;
use App\Services\TicketCommentService;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TicketCommentAttachmentController extends Controller
{
    public function download(TicketComment $comment, TicketCommentService $comments): StreamedResponse
    {
        abort_unless(auth()->check(), 403);

        $ticket = $comment->ticket()->first();
        abort_unless($ticket, 404);
        $this->authorize('view', $ticket);
        abort_unless($comments->attachmentExists($comment), 404);

        return Storage::disk($comment->attachment_disk ?: 'local')->download(
            $comment->attachment_path,
            $comment->attachment_original_name ?: 'attachment.pdf',
        );
    }
}
