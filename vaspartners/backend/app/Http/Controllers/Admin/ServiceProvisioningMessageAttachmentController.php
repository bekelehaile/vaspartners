<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ServiceProvisioningMessage;
use App\Services\Provisioning\ServiceProvisioningMessageService;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ServiceProvisioningMessageAttachmentController extends Controller
{
    /** Inline open in browser — authenticated Filament staff only. */
    public function open(
        ServiceProvisioningMessage $message,
        ServiceProvisioningMessageService $messages,
    ): BinaryFileResponse {
        abort_unless(auth()->check(), 403);

        $case = $message->provisioning()->first();
        abort_unless($case, 404);
        $this->authorize('view', $case);
        abort_unless($messages->attachmentExists($message), 404);

        $disk = $message->attachment_disk ?: 'local';
        $path = (string) $message->attachment_path;
        abort_unless(Storage::disk($disk)->exists($path), 404);

        $absolute = Storage::disk($disk)->path($path);
        $filename = $message->attachment_original_name ?: basename($path);
        $mime = $message->attachment_mime ?: (mime_content_type($absolute) ?: 'application/octet-stream');

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
