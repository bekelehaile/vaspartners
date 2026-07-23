"use client";

import { useState } from "react";
import type { DocumentRequirement, Ticket } from "@/lib/api";
import {
  acceptAttrFromMimes,
  documentsLockedStatus,
  validateFileAgainstDocType,
} from "@/lib/document-upload";
import {
  useDeleteTicketDocument,
  useDocumentRequirements,
  useUploadTicketDocument,
} from "@/hooks/use-customer";

type UploadedSlot = { id: number; name: string };

export function TicketDocumentsPanel({
  ticket,
  mode = "manage",
}: {
  ticket: Ticket;
  /** wizard = local finish gate; manage = detail page */
  mode?: "wizard" | "manage";
}) {
  const serviceId = ticket.service?.id ? String(ticket.service.id) : "";
  const requisitionId = ticket.requisition?.id ? String(ticket.requisition.id) : "";
  const { data: requirements = [] } = useDocumentRequirements(serviceId, requisitionId);
  const upload = useUploadTicketDocument(ticket.public_id);
  const remove = useDeleteTicketDocument(ticket.public_id);
  const [localError, setLocalError] = useState<string | null>(null);

  const locked = documentsLockedStatus(ticket.status, ticket.documents_locked);
  const byType = mapUploadsByType(ticket.documents || []);

  const requiredIds = requirements
    .filter((r) => r.is_required)
    .map((r) => r.document_type.id);
  const allRequiredUploaded = requiredIds.every((id) => byType[id]);

  const busy = upload.isPending || remove.isPending;
  const errorMessage =
    localError ||
    (upload.isError && upload.error instanceof Error ? upload.error.message : null) ||
    (remove.isError && remove.error instanceof Error ? remove.error.message : null);

  function onPick(req: DocumentRequirement, file: File | undefined) {
    setLocalError(null);
    if (!file || locked) return;
    const err = validateFileAgainstDocType(file, req.document_type);
    if (err) {
      setLocalError(err);
      return;
    }
    upload.mutate({ documentTypeId: req.document_type.id, file });
  }

  function onRemove(documentId: number) {
    setLocalError(null);
    if (locked) {
      setLocalError("Documents cannot be removed after this request is approved or closed.");
      return;
    }
    remove.mutate(documentId);
  }

  if (!requirements.length && !(ticket.documents || []).length) {
    return <div className="empty">No documents are required for this request type.</div>;
  }

  return (
    <div className="ticket-docs-panel">
      {locked && (
        <p className="muted ticket-docs-lock-note">
          This request is {ticket.status === "completed" ? "approved" : "closed"}. Documents
          are locked and cannot be uploaded or removed.
        </p>
      )}

      {errorMessage && <div className="alert">{errorMessage}</div>}

      {requirements.length > 0 ? (
        <div className="upload-grid">
          {requirements.map((r) => {
            const uploaded = byType[r.document_type.id];
            return (
              <div
                key={r.id}
                className={`doc-slot${uploaded ? " is-done" : ""}${locked ? " is-locked" : ""}`}
              >
                <label>
                  {r.document_type.name}
                  {r.is_required ? " *" : ""}
                </label>
                <small className="muted">
                  {acceptAttrFromMimes(r.document_type.accepted_mimes)
                    .replaceAll(".", "")
                    .replaceAll(",", ", ")
                    .toUpperCase() || "—"}{" "}
                  · max {r.document_type.max_size_kb} KB
                </small>
                {uploaded ? (
                  <div className="doc-slot-actions">
                    <small style={{ color: "var(--et-green)" }}>
                      Uploaded: {uploaded.name}
                    </small>
                    {!locked && (
                      <button
                        type="button"
                        className="linkish"
                        disabled={busy}
                        onClick={() => onRemove(uploaded.id)}
                      >
                        Remove
                      </button>
                    )}
                  </div>
                ) : locked ? (
                  <small className="muted">Not uploaded</small>
                ) : (
                  <input
                    type="file"
                    accept={acceptAttrFromMimes(r.document_type.accepted_mimes)}
                    disabled={busy}
                    onChange={(e) => {
                      const file = e.target.files?.[0];
                      e.target.value = "";
                      onPick(r, file);
                    }}
                  />
                )}
              </div>
            );
          })}
        </div>
      ) : (
        <ul className="ticket-docs-list">
          {(ticket.documents || []).map((d) => (
            <li key={d.id}>
              <span>
                {d.document_type?.name || "Document"} — {d.original_name}
              </span>
              {!locked && (
                <button
                  type="button"
                  className="linkish"
                  disabled={busy}
                  onClick={() => onRemove(d.id)}
                >
                  Remove
                </button>
              )}
            </li>
          ))}
        </ul>
      )}

      {mode === "wizard" && !locked && requiredIds.length > 0 && !allRequiredUploaded && (
        <p className="muted" style={{ marginTop: "0.85rem" }}>
          Upload all required documents before finishing.
        </p>
      )}
    </div>
  );
}

function mapUploadsByType(
  documents: NonNullable<Ticket["documents"]>
): Record<number, UploadedSlot> {
  const map: Record<number, UploadedSlot> = {};
  for (const d of documents) {
    const typeId = d.document_type_id ?? d.document_type?.id;
    if (!typeId) continue;
    map[typeId] = { id: d.id, name: d.original_name };
  }
  return map;
}
