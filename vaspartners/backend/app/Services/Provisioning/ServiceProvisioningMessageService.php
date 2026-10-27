<?php

namespace App\Services\Provisioning;

use App\Enums\ProvisioningStatus;
use App\Enums\ProvisioningStepStatus;
use App\Models\Contact;
use App\Models\ServiceProvisioning;
use App\Models\ServiceProvisioningMessage;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ServiceProvisioningMessageService
{
    public function maxAttachmentKb(): int
    {
        return max(1, (int) config('vas.chat_attachment_max_kb', 2048));
    }

    /**
     * Case is finished — no new messages from anyone (except allowWhenLocked).
     */
    public function chatLocked(ServiceProvisioning $case): bool
    {
        return in_array($case->status, [
            ProvisioningStatus::GoLive,
            ProvisioningStatus::Cancelled,
            ProvisioningStatus::Rejected,
        ], true);
    }

    /**
     * Partner may message after credentials are shared, until they submit completion
     * (Completed / Cancelled on configuration confirmation).
     */
    public function partnerMessagingOpen(ServiceProvisioning $case): bool
    {
        if ($this->chatLocked($case)) {
            return false;
        }

        $shared = $case->shared_payload ?? [];
        if (! (bool) ($shared['credentials_released_to_partner'] ?? false)) {
            return false;
        }

        if (! empty($shared['partner_messaging_closed_at'])) {
            return false;
        }

        $configStep = $case->relationLoaded('steps')
            ? $case->steps->firstWhere('step_key', 'partner_config_completed')
            : $case->steps()->where('step_key', 'partner_config_completed')->first();

        if ($configStep && in_array($configStep->status, [
            ProvisioningStepStatus::Completed,
            ProvisioningStepStatus::Rejected,
            ProvisioningStepStatus::Skipped,
        ], true)) {
            return false;
        }

        return true;
    }

    /**
     * Whether this author may post on the case right now.
     */
    public function authorCanPost(ServiceProvisioning $case, Contact|User $author): bool
    {
        if ($this->chatLocked($case)) {
            return false;
        }

        if ($author instanceof Contact) {
            return $this->partnerMessagingOpen($case);
        }

        return true;
    }

    /**
     * @return 'closed'|'not_ready'|null
     */
    public function partnerChatBlockReason(ServiceProvisioning $case): ?string
    {
        if ($this->chatLocked($case)) {
            return 'closed';
        }

        $shared = $case->shared_payload ?? [];
        if (! empty($shared['partner_messaging_closed_at'])
            || ! (bool) ($shared['credentials_released_to_partner'] ?? false)) {
            // After completion submit, or before credentials — same partner-facing lock states.
            return ! empty($shared['partner_messaging_closed_at']) ? 'closed' : 'not_ready';
        }

        if (! $this->partnerMessagingOpen($case)) {
            return 'closed';
        }

        return null;
    }

    /**
     * @param  Contact|User  $author
     */
    public function post(
        ServiceProvisioning $case,
        Contact|User $author,
        ?string $body,
        ?UploadedFile $file = null,
        bool $isPublic = true,
        bool $allowWhenLocked = false,
    ): ServiceProvisioningMessage {
        if (! $allowWhenLocked && $this->chatLocked($case)) {
            throw ValidationException::withMessages([
                'body' => 'Messaging is closed for this completed provisioning case.',
            ]);
        }

        if ($author instanceof Contact) {
            if ((int) $author->company_id !== (int) $case->company_id) {
                throw ValidationException::withMessages([
                    'body' => 'You cannot message on this provisioning case.',
                ]);
            }
            if (! $allowWhenLocked && ! $this->partnerMessagingOpen($case)) {
                throw ValidationException::withMessages([
                    'body' => 'Messaging opens after Ethio telecom shares credentials for configuration.',
                ]);
            }
            $isPublic = true;
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

        $attachment = $file ? $this->storePdf($case, $file) : null;

        return DB::transaction(function () use ($case, $author, $body, $attachment, $isPublic) {
            return ServiceProvisioningMessage::query()->create([
                'service_provisioning_id' => $case->id,
                'author_type' => $author::class,
                'author_id' => $author->getKey(),
                'body' => $body !== '' ? $body : '(PDF attachment)',
                'is_public' => $isPublic,
                'attachment_disk' => $attachment['disk'] ?? null,
                'attachment_path' => $attachment['path'] ?? null,
                'attachment_original_name' => $attachment['original_name'] ?? null,
                'attachment_mime' => $attachment['mime'] ?? null,
                'attachment_size_bytes' => $attachment['size'] ?? null,
            ]);
        });
    }

    /**
     * @return array{disk: string, path: string, original_name: string, mime: string, size: int}
     */
    protected function storePdf(ServiceProvisioning $case, UploadedFile $file): array
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

        $head = file_get_contents($file->getRealPath(), false, null, 0, 5);
        if ($head !== '%PDF-') {
            throw ValidationException::withMessages([
                'attachment' => 'The file does not look like a valid PDF.',
            ]);
        }

        $disk = 'local';
        $dir = 'provisioning-chat/'.$case->id;
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

    public function attachmentExists(ServiceProvisioningMessage $message): bool
    {
        if (blank($message->attachment_path)) {
            return false;
        }

        return Storage::disk($message->attachment_disk ?: 'local')->exists($message->attachment_path);
    }

    /**
     * @return array{
     *   data: list<array<string, mixed>>,
     *   meta: array{total: int, has_more_older: bool, has_more_newer: bool, oldest_id: int|null, newest_id: int|null}
     * }
     */
    public function paginateThread(
        ServiceProvisioning $case,
        ?Contact $viewer = null,
        ?int $beforeId = null,
        ?int $afterId = null,
        int $limit = 30,
    ): array {
        $limit = max(5, min(100, $limit));

        $base = ServiceProvisioningMessage::query()
            ->where('service_provisioning_id', $case->id)
            ->with('author');

        // Partners only see public messages.
        if ($viewer instanceof Contact) {
            $base->where('is_public', true);
        }

        $total = (clone $base)->count();

        $query = clone $base;
        if ($beforeId) {
            $query->where('id', '<', $beforeId)->orderByDesc('id');
        } elseif ($afterId) {
            $query->where('id', '>', $afterId)->orderBy('id');
        } else {
            $query->orderByDesc('id');
        }

        $rows = $query->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        if ($hasMore) {
            $rows = $rows->take($limit);
        }

        if (! $afterId) {
            $rows = $rows->sortBy('id')->values();
        } else {
            $rows = $rows->values();
        }

        $data = $rows->map(fn (ServiceProvisioningMessage $m) => $this->serialize($case, $m))->all();

        return [
            'data' => $data,
            'meta' => [
                'total' => $total,
                'has_more_older' => $beforeId ? $hasMore : ($afterId ? false : $hasMore),
                'has_more_newer' => (bool) $afterId && $hasMore,
                'oldest_id' => $rows->first()?->id,
                'newest_id' => $rows->last()?->id,
            ],
        ];
    }

    public function serialize(ServiceProvisioning $case, ServiceProvisioningMessage $message): array
    {
        $author = $message->author;
        $isContact = $author instanceof Contact;

        return [
            'id' => $message->id,
            'body' => ($message->body === '(PDF attachment)' && filled($message->attachment_path))
                ? ''
                : $message->body,
            'author_role' => $isContact ? 'contact' : 'staff',
            'author_label' => $isContact
                ? ($author->name ?: 'Partner')
                : ($author instanceof User ? ($author->name ?: 'Account manager') : 'Staff'),
            'has_attachment' => filled($message->attachment_path),
            'attachment_name' => $message->attachment_original_name,
            'attachment_size_bytes' => $message->attachment_size_bytes,
            'attachment_url' => filled($message->attachment_path)
                ? url('/api/v1/provisioning/'.$case->public_id.'/messages/'.$message->id.'/attachment')
                : null,
            'created_at' => optional($message->created_at)?->toIso8601String(),
        ];
    }
}
