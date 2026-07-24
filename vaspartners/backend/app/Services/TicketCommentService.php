<?php

namespace App\Services;

use App\Enums\TicketStatus;
use App\Models\Customer;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TicketCommentService
{
    public function maxAttachmentKb(): int
    {
        return max(1, (int) config('vas.chat_attachment_max_kb', 2048));
    }

    /**
     * @param  Customer|User  $author
     */
    public function post(Ticket $ticket, Customer|User $author, ?string $body, ?UploadedFile $file = null): TicketComment
    {
        if ($ticket->status instanceof TicketStatus && $ticket->status->locksCustomerChat()) {
            throw ValidationException::withMessages([
                'body' => 'This request is closed for new messages.',
            ]);
        }

        $body = filled($body) ? trim((string) $body) : '';
        if ($body === '' && ! $file) {
            throw ValidationException::withMessages([
                'body' => 'Enter a message or attach a small PDF.',
            ]);
        }

        if ($body !== '' && mb_strlen($body) > 5000) {
            throw ValidationException::withMessages([
                'body' => 'Message may not be longer than 5000 characters.',
            ]);
        }

        $attachment = $file ? $this->storePdf($ticket, $file) : null;

        return DB::transaction(function () use ($ticket, $author, $body, $attachment) {
            return TicketComment::query()->create([
                'ticket_id' => $ticket->id,
                'author_type' => $author::class,
                'author_id' => $author->id,
                'body' => $body !== '' ? $body : '(PDF attachment)',
                'is_public' => true,
                'attachment_disk' => $attachment['disk'] ?? null,
                'attachment_path' => $attachment['path'] ?? null,
                'attachment_original_name' => $attachment['original_name'] ?? null,
                'attachment_mime' => $attachment['mime'] ?? null,
                'attachment_size_bytes' => $attachment['size'] ?? null,
            ]);
        });
    }

    /** @return array{disk: string, path: string, original_name: string, mime: string, size: int} */
    protected function storePdf(Ticket $ticket, UploadedFile $file): array
    {
        $maxKb = $this->maxAttachmentKb();

        if ($file->getSize() === false || $file->getSize() < 1) {
            throw ValidationException::withMessages([
                'attachment' => 'The attached file is empty.',
            ]);
        }

        if ($file->getSize() > $maxKb * 1024) {
            throw ValidationException::withMessages([
                'attachment' => "PDF must be {$maxKb} KB or smaller.",
            ]);
        }

        $ext = strtolower($file->getClientOriginalExtension() ?: '');
        $mime = strtolower((string) ($file->getMimeType() ?: ''));
        if ($ext !== 'pdf' || ! in_array($mime, ['application/pdf', 'application/x-pdf'], true)) {
            throw ValidationException::withMessages([
                'attachment' => 'Only PDF files are allowed in chat.',
            ]);
        }

        // Light spoofing check
        $head = file_get_contents($file->getRealPath(), false, null, 0, 5);
        if ($head !== '%PDF-') {
            throw ValidationException::withMessages([
                'attachment' => 'The file does not look like a valid PDF.',
            ]);
        }

        $disk = 'local';
        $dir = 'ticket-chat/'.$ticket->id;
        $name = Str::uuid()->toString().'.pdf';
        $path = $file->storeAs($dir, $name, $disk);

        return [
            'disk' => $disk,
            'path' => $path,
            'original_name' => $file->getClientOriginalName() ?: 'attachment.pdf',
            'mime' => 'application/pdf',
            'size' => (int) $file->getSize(),
        ];
    }

    public function attachmentExists(TicketComment $comment): bool
    {
        if (blank($comment->attachment_path)) {
            return false;
        }

        return Storage::disk($comment->attachment_disk ?: 'local')->exists($comment->attachment_path);
    }

    /**
     * @return array{
     *   data: list<array<string, mixed>>,
     *   meta: array{total: int, has_more_older: bool, has_more_newer: bool, oldest_id: int|null, newest_id: int|null}
     * }
     */
    public function paginateThread(
        Ticket $ticket,
        ?Customer $viewer = null,
        ?int $beforeId = null,
        ?int $afterId = null,
        int $limit = 30,
    ): array {
        $limit = max(5, min(100, $limit));

        $base = TicketComment::query()
            ->where('ticket_id', $ticket->id)
            ->where('is_public', true);

        $total = (clone $base)->count();

        $query = (clone $base)->with('author');

        if ($beforeId) {
            // Older page (scroll up): ids strictly less than beforeId, newest-first then reverse
            $rows = $query->where('id', '<', $beforeId)
                ->orderByDesc('id')
                ->limit($limit)
                ->get()
                ->sortBy('id')
                ->values();
        } elseif ($afterId) {
            // Newer page (poll): ids strictly greater than afterId, oldest-first
            $rows = $query->where('id', '>', $afterId)
                ->orderBy('id')
                ->limit($limit)
                ->get()
                ->values();
        } else {
            // Initial: latest N, oldest-first for display
            $rows = $query->orderByDesc('id')
                ->limit($limit)
                ->get()
                ->sortBy('id')
                ->values();
        }

        $data = $rows->map(fn (TicketComment $comment) => $this->serializeComment($ticket, $comment, $viewer))->all();
        $oldestId = $rows->first()?->id;
        $newestId = $rows->last()?->id;

        $hasMoreOlder = $oldestId
            ? (clone $base)->where('id', '<', $oldestId)->exists()
            : false;
        $hasMoreNewer = $newestId
            ? (clone $base)->where('id', '>', $newestId)->exists()
            : false;

        return [
            'data' => $data,
            'meta' => [
                'total' => $total,
                'has_more_older' => $hasMoreOlder,
                'has_more_newer' => $hasMoreNewer,
                'oldest_id' => $oldestId,
                'newest_id' => $newestId,
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    public function serializeThread(Ticket $ticket, ?Customer $viewer = null): array
    {
        return $this->paginateThread($ticket, $viewer, null, null, 40)['data'];
    }

    /** @return array<string, mixed> */
    public function serializeComment(Ticket $ticket, TicketComment $comment, ?Customer $viewer = null): array
    {
        $author = $comment->author;
        $isStaff = $author instanceof User;
        $isSelf = $viewer && $author instanceof Customer && (int) $author->id === (int) $viewer->id;

        return [
            'id' => $comment->id,
            'body' => ($comment->body === '(PDF attachment)' && filled($comment->attachment_path))
                ? null
                : $comment->body,
            'author_role' => $isStaff ? 'staff' : 'customer',
            'author_label' => $isStaff
                ? ($author->name ?: 'Account manager')
                : ($isSelf ? 'You' : ($author->name ?? 'Partner')),
            'has_attachment' => filled($comment->attachment_path),
            'attachment_name' => $comment->attachment_original_name,
            'attachment_size_bytes' => $comment->attachment_size_bytes,
            'attachment_url' => filled($comment->attachment_path)
                ? url("/api/v1/tickets/{$ticket->public_id}/comments/{$comment->id}/attachment")
                : null,
            'created_at' => optional($comment->created_at)?->toIso8601String(),
        ];
    }
}
