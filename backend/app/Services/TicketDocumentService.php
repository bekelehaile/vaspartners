<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\DocumentType;
use App\Models\ServiceRequisitionDocument;
use App\Models\Ticket;
use App\Models\TicketDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class TicketDocumentService
{
    /**
     * Allowed MIME types for each admin-configured extension.
     *
     * @var array<string, list<string>>
     */
    public const EXTENSION_MIME_MAP = [
        'pdf' => ['application/pdf'],
        'doc' => ['application/msword'],
        'docx' => [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/zip', // some browsers sniff docx as zip
        ],
        'xls' => ['application/vnd.ms-excel', 'application/msexcel'],
        'xlsx' => [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/zip',
        ],
        'png' => ['image/png'],
        'jpg' => ['image/jpeg', 'image/jpg'],
        'jpeg' => ['image/jpeg', 'image/jpg'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
        'txt' => ['text/plain'],
        'csv' => ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'],
        'zip' => ['application/zip', 'application/x-zip-compressed', 'multipart/x-zip'],
    ];

    public function assertCustomerCanMutateDocuments(Ticket $ticket): void
    {
        if ($ticket->customerDocumentsAreLocked()) {
            throw ValidationException::withMessages([
                'documents' => 'Documents cannot be changed after this request is approved or closed.',
            ]);
        }
    }

    public function storeForCustomer(Ticket $ticket, Customer $customer, int $documentTypeId, UploadedFile $file): TicketDocument
    {
        abort_unless($ticket->customer_id === $customer->id, 404);
        $this->assertCustomerCanMutateDocuments($ticket);

        $documentType = $this->resolveAllowedDocumentType($ticket, $documentTypeId);
        $this->assertFileMatchesDocumentType($file, $documentType);

        // One current file per document type — replace previous customer upload.
        $previous = $ticket->documents()
            ->where('document_type_id', $documentType->id)
            ->get();

        $path = $file->store('tickets/'.$ticket->public_id, 'local');

        $doc = TicketDocument::query()->create([
            'ticket_id' => $ticket->id,
            'document_type_id' => $documentType->id,
            'disk' => 'local',
            'path' => $path,
            'original_name' => $this->safeOriginalName($file),
            'mime_type' => $file->getMimeType() ?: $file->getClientMimeType(),
            'size_bytes' => $file->getSize(),
            'uploaded_by_customer_id' => $customer->id,
        ]);

        foreach ($previous as $old) {
            $this->purgeStoredFile($old);
            $old->delete();
        }

        return $doc->load('documentType');
    }

    public function deleteForCustomer(Ticket $ticket, TicketDocument $document, Customer $customer): void
    {
        abort_unless($ticket->customer_id === $customer->id, 404);
        abort_unless($document->ticket_id === $ticket->id, 404);
        $this->assertCustomerCanMutateDocuments($ticket);

        $this->purgeStoredFile($document);
        $document->delete();
    }

    public function resolveAllowedDocumentType(Ticket $ticket, int $documentTypeId): DocumentType
    {
        $allowed = ServiceRequisitionDocument::query()
            ->where('service_id', $ticket->service_id)
            ->where('requisition_id', $ticket->requisition_id)
            ->where('document_type_id', $documentTypeId)
            ->exists();

        if (! $allowed) {
            throw ValidationException::withMessages([
                'document_type_id' => 'This document type is not configured for this service request.',
            ]);
        }

        $documentType = DocumentType::query()
            ->whereKey($documentTypeId)
            ->where('is_active', true)
            ->first();

        if (! $documentType) {
            throw ValidationException::withMessages([
                'document_type_id' => 'This document type is not available.',
            ]);
        }

        return $documentType;
    }

    public function assertFileMatchesDocumentType(UploadedFile $file, DocumentType $documentType): void
    {
        if (! $file->isValid()) {
            throw ValidationException::withMessages([
                'file' => 'The uploaded file is invalid or incomplete.',
            ]);
        }

        $extensions = $this->acceptedExtensions($documentType);
        if ($extensions === []) {
            throw ValidationException::withMessages([
                'file' => 'No accepted file types are configured for '.$documentType->name.'.',
            ]);
        }

        $maxKb = max(1, (int) $documentType->max_size_kb);
        $label = $documentType->name;
        $allowedList = implode(', ', $extensions);

        // Laravel enforces size (KB) + mime/extension sniff against admin config.
        $validator = Validator::make(
            ['file' => $file],
            [
                'file' => [
                    'required',
                    'file',
                    'max:'.$maxKb,
                    'mimes:'.implode(',', $extensions),
                ],
            ],
            [
                'file.required' => "A file is required for {$label}.",
                'file.file' => "The upload for {$label} must be a file.",
                'file.max' => "{$label} must be {$maxKb} KB or smaller (admin limit).",
                'file.mimes' => "{$label} must be one of: {$allowedList} (admin config).",
            ]
        );

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }

        $sizeBytes = (int) $file->getSize();
        if ($sizeBytes <= 0) {
            throw ValidationException::withMessages([
                'file' => "{$label} cannot be an empty file.",
            ]);
        }

        $maxBytes = $maxKb * 1024;
        if ($sizeBytes > $maxBytes) {
            throw ValidationException::withMessages([
                'file' => "{$label} must be {$maxKb} KB or smaller (admin limit).",
            ]);
        }

        $ext = strtolower((string) $file->getClientOriginalExtension());
        $guessedExt = strtolower((string) ($file->guessExtension() ?: ''));
        $detectedMime = strtolower((string) ($file->getMimeType() ?: ''));
        $clientMime = strtolower((string) ($file->getClientMimeType() ?: ''));

        if ($ext === '' || ! in_array($ext, $extensions, true)) {
            throw ValidationException::withMessages([
                'file' => "{$label} file extension must be one of: {$allowedList}.",
            ]);
        }

        // Reject extension spoofing when PHP can guess a different extension.
        if ($guessedExt !== '' && ! in_array($guessedExt, $extensions, true)) {
            // jpg/jpeg are interchangeable when both allowed or either maps to jpeg.
            $jpegFamily = ['jpg', 'jpeg'];
            $spoofed = ! (
                in_array($ext, $jpegFamily, true)
                && in_array($guessedExt, $jpegFamily, true)
                && count(array_intersect($extensions, $jpegFamily)) > 0
            );

            if ($spoofed) {
                throw ValidationException::withMessages([
                    'file' => "{$label} content does not match an allowed type ({$allowedList}).",
                ]);
            }
        }

        $allowedMimes = $this->allowedMimeTypesForExtensions($extensions);
        if ($allowedMimes !== [] && $detectedMime !== '') {
            if (! in_array($detectedMime, $allowedMimes, true)) {
                throw ValidationException::withMessages([
                    'file' => "{$label} content type \"{$detectedMime}\" is not allowed. Use: {$allowedList}.",
                ]);
            }
        }

        if ($clientMime !== '' && $clientMime !== 'application/octet-stream' && $allowedMimes !== []) {
            if (! in_array($clientMime, $allowedMimes, true)) {
                throw ValidationException::withMessages([
                    'file' => "{$label} reported type \"{$clientMime}\" is not allowed. Use: {$allowedList}.",
                ]);
            }
        }
    }

    /** @return list<string> */
    public function acceptedExtensions(DocumentType $documentType): array
    {
        $parts = preg_split('/[,\s]+/', (string) $documentType->accepted_mimes) ?: [];

        return array_values(array_unique(array_filter(array_map(
            static fn (string $part): string => strtolower(trim($part, " \t\n\r\0\x0B.")),
            $parts
        ))));
    }

    /**
     * @param  list<string>  $extensions
     * @return list<string>
     */
    public function allowedMimeTypesForExtensions(array $extensions): array
    {
        $mimes = [];
        foreach ($extensions as $ext) {
            foreach (self::EXTENSION_MIME_MAP[$ext] ?? [] as $mime) {
                $mimes[$mime] = true;
            }
        }

        return array_keys($mimes);
    }

    protected function safeOriginalName(UploadedFile $file): string
    {
        $name = basename((string) $file->getClientOriginalName());
        $name = preg_replace('/[^\w.\- ()\[\]]+/u', '_', $name) ?: 'upload';

        return mb_substr($name, 0, 180);
    }

    protected function purgeStoredFile(TicketDocument $document): void
    {
        if ($document->path) {
            Storage::disk($document->disk ?: 'local')->delete($document->path);
        }
    }
}
