<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\DocumentType;
use App\Models\ServiceRequisitionDocument;
use App\Models\Ticket;
use App\Models\TicketDocument;
use App\Support\SecureUploadedFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class TicketDocumentService
{
    /** @deprecated Use SecureUploadedFile::EXTENSION_MIME_MAP */
    public const EXTENSION_MIME_MAP = SecureUploadedFile::EXTENSION_MIME_MAP;

    public function assertContactCanMutateDocuments(Ticket $ticket): void
    {
        if ($ticket->contactDocumentsAreLocked()) {
            throw ValidationException::withMessages([
                'documents' => 'Documents cannot be changed while this request is being handled. You can update them if it is sent back (rejected) for corrections.',
            ]);
        }
    }

    public function storeForContact(Ticket $ticket, Contact $contact, int $documentTypeId, UploadedFile $file): TicketDocument
    {
        // Caller must authorize company/ticket access (portal allows any active company member).
        $this->assertContactCanMutateDocuments($ticket);

        $documentType = $this->resolveAllowedDocumentType($ticket, $documentTypeId);
        $this->assertFileMatchesDocumentType($file, $documentType);

        // One current file per document type — replace previous contact upload.
        $previous = $ticket->documents()
            ->where('document_type_id', $documentType->id)
            ->get();

        $path = $file->store('tickets/'.$ticket->public_id, 'public');

        $doc = TicketDocument::query()->create([
            'ticket_id' => $ticket->id,
            'document_type_id' => $documentType->id,
            'disk' => 'public',
            'path' => $path,
            'original_name' => $this->safeOriginalName($file),
            'mime_type' => $file->getMimeType() ?: $file->getClientMimeType(),
            'size_bytes' => $file->getSize(),
            'uploaded_by_contact_id' => $contact->id,
        ]);

        foreach ($previous as $old) {
            $this->purgeStoredFile($old);
            $old->delete();
        }

        return $doc->load('documentType');
    }

    public function deleteForContact(Ticket $ticket, TicketDocument $document, Contact $contact): void
    {
        // Caller must authorize company/ticket access (portal allows any active company member).
        abort_unless($document->ticket_id === $ticket->id, 404);
        $this->assertContactCanMutateDocuments($ticket);

        $typeId = (int) $document->document_type_id;
        $siblings = TicketDocument::query()
            ->where('ticket_id', $ticket->id)
            ->where('document_type_id', $typeId)
            ->where('id', '!=', $document->id)
            ->exists();

        if (! $siblings) {
            $hardRequiredIds = app(TicketWorkflowService::class)
                ->requiredDocumentTypeIds((int) $ticket->service_id, (int) $ticket->requisition_id);
            if (in_array($typeId, $hardRequiredIds, true)) {
                throw ValidationException::withMessages([
                    'document' => 'This document is required for this service request. Upload a replacement before removing it.',
                ]);
            }
        }

        $this->purgeStoredFile($document);
        $document->delete();

        // If a required matrix row was added later (or soft-optional edge cases), keep status consistent.
        app(TicketWorkflowService::class)->rejectForIncompleteDocuments($ticket->fresh() ?? $ticket, notify: true);
    }

    public function resolveAllowedDocumentType(Ticket $ticket, int $documentTypeId): DocumentType
    {
        return $this->resolveDocumentTypeForMatrix(
            (int) $ticket->service_id,
            (int) $ticket->requisition_id,
            $documentTypeId,
        );
    }

    public function resolveDocumentTypeForMatrix(int $serviceId, int $requisitionId, int $documentTypeId): DocumentType
    {
        $allowed = ServiceRequisitionDocument::query()
            ->where('service_id', $serviceId)
            ->where('requisition_id', $requisitionId)
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
        SecureUploadedFile::assert(
            $file,
            'file',
            $documentType->name,
            $this->acceptedExtensions($documentType),
            max(1, (int) $documentType->max_size_kb),
        );
    }

    /** @return list<string> */
    public function acceptedExtensions(DocumentType $documentType): array
    {
        return SecureUploadedFile::normalizeExtensions((string) $documentType->accepted_mimes);
    }

    /**
     * @param  list<string>  $extensions
     * @return list<string>
     */
    public function allowedMimeTypesForExtensions(array $extensions): array
    {
        return SecureUploadedFile::mimeTypesForExtensions($extensions);
    }

    protected function safeOriginalName(UploadedFile $file): string
    {
        return SecureUploadedFile::safeOriginalName($file);
    }

    protected function purgeStoredFile(TicketDocument $document): void
    {
        if ($document->path) {
            Storage::disk($document->disk ?: 'public')->delete($document->path);
        }
    }
}
