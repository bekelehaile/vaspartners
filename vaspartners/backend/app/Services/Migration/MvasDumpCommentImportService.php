<?php

namespace App\Services\Migration;

use App\Models\Contact;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\User;
use App\Support\Migration\MvasDumpTableReader;
use App\Support\Migration\MvasStaffLegacyMap;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Import MVAS ticket comments (partner + staff messages) into ticket_comments.
 */
class MvasDumpCommentImportService
{
    public function __construct(
        private readonly MvasDumpTableReader $tableReader,
    ) {}

    /**
     * @param  array{dump: string, dry_run?: bool, limit?: int|null}  $options
     * @return array<string, int>
     */
    public function import(array $options): array
    {
        $dump = (string) $options['dump'];
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $limit = isset($options['limit']) ? max(0, (int) $options['limit']) : null;

        $stats = [
            'selected' => 0,
            'imported' => 0,
            'skipped_existing' => 0,
            'skipped_no_ticket' => 0,
            'skipped_no_author' => 0,
            'skipped_deleted' => 0,
            'skipped_empty' => 0,
            'public' => 0,
            'internal' => 0,
            'author_staff' => 0,
            'author_contact' => 0,
        ];

        $ticketsByLegacy = Ticket::withTrashed()
            ->whereNotNull('legacy_mvas_ticket_id')
            ->get(['id', 'legacy_mvas_ticket_id', 'contact_id'])
            ->keyBy(fn (Ticket $t): int => (int) $t->legacy_mvas_ticket_id);

        if ($ticketsByLegacy->isEmpty()) {
            return $stats;
        }

        /** @var array<int, array{type: string, id: int}> $authorsByComment */
        $authorsByComment = [];
        foreach ($this->tableReader->rows($dump, 'commentables') as $row) {
            $commentId = (int) ($row[1] ?? 0);
            $type = (string) ($row[2] ?? '');
            $authorId = (int) ($row[3] ?? 0);
            if ($commentId < 1 || $authorId < 1 || $type === '') {
                continue;
            }
            $authorsByComment[$commentId] = ['type' => $type, 'id' => $authorId];
        }

        $staffUsers = $this->staffUsersByLegacyId();
        $contactsByLegacy = Contact::query()
            ->whereNotNull('legacy_mvas_id')
            ->get(['id', 'legacy_mvas_id'])
            ->keyBy(fn (Contact $c): int => (int) $c->legacy_mvas_id);

        $existingLegacyIds = TicketComment::withTrashed()
            ->whereNotNull('legacy_mvas_comment_id')
            ->pluck('legacy_mvas_comment_id')
            ->map(fn ($id) => (int) $id)
            ->flip()
            ->all();

        foreach ($this->tableReader->rows($dump, 'comments') as $row) {
            // comments: id, user_id, ticket_id, comment, is_public, created_at, updated_at, deleted_at
            $legacyCommentId = (int) ($row[0] ?? 0);
            $legacyTicketId = (int) ($row[2] ?? 0);
            $body = trim((string) ($row[3] ?? ''));
            $isPublic = ((string) ($row[4] ?? '0')) === '1';
            $createdAt = $this->parseTime($row[5] ?? null);
            $updatedAt = $this->parseTime($row[6] ?? null) ?? $createdAt;
            $deletedAt = $this->parseTime($row[7] ?? null);

            if ($legacyCommentId < 1 || $legacyTicketId < 1) {
                continue;
            }

            if ($limit !== null && $stats['selected'] >= $limit) {
                break;
            }
            $stats['selected']++;

            if ($deletedAt !== null) {
                $stats['skipped_deleted']++;

                continue;
            }

            if ($body === '') {
                $stats['skipped_empty']++;

                continue;
            }

            if (isset($existingLegacyIds[$legacyCommentId])) {
                $stats['skipped_existing']++;

                continue;
            }

            $ticket = $ticketsByLegacy->get($legacyTicketId);
            if (! $ticket) {
                $stats['skipped_no_ticket']++;

                continue;
            }

            $author = $this->resolveAuthor(
                $authorsByComment[$legacyCommentId] ?? null,
                (int) ($row[1] ?? 0),
                $staffUsers,
                $contactsByLegacy,
                $ticket,
            );

            if ($author === null) {
                $stats['skipped_no_author']++;

                continue;
            }

            if ($author instanceof User) {
                $stats['author_staff']++;
            } else {
                $stats['author_contact']++;
            }

            if ($isPublic) {
                $stats['public']++;
            } else {
                $stats['internal']++;
            }

            if ($dryRun) {
                $stats['imported']++;

                continue;
            }

            $comment = new TicketComment([
                'ticket_id' => $ticket->id,
                'author_type' => $author::class,
                'author_id' => $author->id,
                'body' => $body,
                'is_public' => $isPublic,
                'legacy_mvas_comment_id' => $legacyCommentId,
            ]);
            $comment->created_at = $createdAt ?? now();
            $comment->updated_at = $updatedAt ?? $comment->created_at;
            $comment->saveQuietly();

            $existingLegacyIds[$legacyCommentId] = true;
            $stats['imported']++;
        }

        Log::info('mvas comments imported', $stats);

        return $stats;
    }

    /**
     * @param  array{type: string, id: int}|null  $meta
     * @param  array<int, User>  $staffUsers
     * @param  \Illuminate\Support\Collection<int, Contact>  $contactsByLegacy
     */
    protected function resolveAuthor(
        ?array $meta,
        int $fallbackUserId,
        array $staffUsers,
        $contactsByLegacy,
        Ticket $ticket,
    ): User|Contact|null {
        $type = $meta['type'] ?? null;
        $id = $meta['id'] ?? 0;

        if ($type && str_contains($type, 'Client') && $id > 0) {
            return $contactsByLegacy->get($id)
                ?? ($ticket->contact_id ? Contact::query()->find($ticket->contact_id) : null);
        }

        if ($type && str_contains($type, 'User') && $id > 0) {
            return $staffUsers[$id] ?? null;
        }

        // Fallback: treat comments.user_id as staff first, then client.
        if ($fallbackUserId > 0) {
            if (isset($staffUsers[$fallbackUserId])) {
                return $staffUsers[$fallbackUserId];
            }
            if ($contactsByLegacy->has($fallbackUserId)) {
                return $contactsByLegacy->get($fallbackUserId);
            }
        }

        return $ticket->contact_id ? Contact::query()->find($ticket->contact_id) : null;
    }

    /**
     * @return array<int, User>
     */
    protected function staffUsersByLegacyId(): array
    {
        $emails = MvasStaffLegacyMap::emailsByLegacyId();
        $users = User::query()
            ->whereIn('email', array_values($emails))
            ->get()
            ->keyBy(fn (User $u): string => strtolower((string) $u->email));

        $map = [];
        foreach ($emails as $legacyId => $email) {
            $user = $users->get(strtolower($email));
            if ($user) {
                $map[(int) $legacyId] = $user;
            }
        }

        return $map;
    }

    protected function parseTime(mixed $value): ?Carbon
    {
        if ($value === null || $value === '' || $value === 'NULL') {
            return null;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }
}
