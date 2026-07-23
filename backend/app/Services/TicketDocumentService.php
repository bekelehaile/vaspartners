<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\DocumentType;
use App\Models\ServiceRequisitionDocument;
use App\Models\Ticket;
use App\Models\TicketDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class TicketDocumentService
{
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
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType() ?: $file->getMimeType(),
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
        $extensions = $this->acceptedExtensions($documentType);
        if ($extensions === []) {
            throw ValidationException::withMessages([
                'file' => 'No accepted file types are configured for this document.',
            ]);
        }

        $ext = strtolower((string) $file->getClientOriginalExtension());
        if ($ext === '' || ! in_array($ext, $extensions, true)) {
            throw ValidationException::withMessages([
                'file' => 'File type must be one of: '.implode(', ', $extensions).'.',
            ]);
        }

        $maxKb = max(1, (int) $documentType->max_size_kb);
        $sizeKb = (int) ceil($file->getSize() / 1024);
        if ($sizeKb > $maxKb) {
            throw ValidationException::withMessages([
                'file' => "File must be {$maxKb} KB or smaller.",
            ]);
        }
    }

    /** @return list<string> */
    public function acceptedExtensions(DocumentType $documentType): array
    {
        return array_values(array_filter(array_map(
            static fn (string $part): string => strtolower(trim($part)),
            explode(',', (string) $documentType->accepted_mimes)
        )));
    }

    protected function purgeStoredFile(TicketDocument $document): void
    {
        if ($document->path) {
            Storage::disk($document->disk ?: 'local')->delete($document->path);
        }
    }
}
