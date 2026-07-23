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
  serviceId: serviceIdProp,
  requisitionId: requisitionIdProp,
}: {
  ticket: Ticket;
  /** wizard = local finish gate; manage = detail page */
  mode?: "wizard" | "manage";
  /** Prefer explicit ids so uploads work even if ticket relations are thin. */
  serviceId?: string;
  requisitionId?: string;
}) {
  const serviceId =
    serviceIdProp ||
    (ticket.service?.id ? String(ticket.service.id) : "") ||
    (ticket as Ticket & { service_id?: number }).service_id?.toString() ||
    "";
  const requisitionId =
    requisitionIdProp ||
    (ticket.requisition?.id ? String(ticket.requisition.id) : "") ||
    (ticket as Ticket & { requisition_id?: number }).requisition_id?.toString() ||
    "";

  const { data: requirements = [], isLoading } = useDocumentRequirements(
    serviceId,
    requisitionId
  );
  const upload = useUploadTicketDocument(ticket.public_id);
  const remove = useDeleteTicketDocument(ticket.public_id);
  const [localError, setLocalError] = useState<string | null>(null);

  const locked = documentsLockedStatus(ticket.status, ticket.documents_locked);
  const byType = mapUploadsByType(ticket.documents || []);

  const softOptional = (r: DocumentRequirement) =>
    r.document_type.code === "document-if-any" || /if any/i.test(r.document_type.name);

  const requiredIds = requirements
    .filter((r) => r.is_required && !softOptional(r))
    .map((r) => r.document_type.id);
  const missingRequired = requirements.filter(
    (r) => r.is_required && !softOptional(r) && !byType[r.document_type.id]
  );

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
                    setLocalError("Documents cannot be changed while this request is being handled.");
      return;
    }
    remove.mutate(documentId);
  }

  if (isLoading) {
    return <p className="muted">Loading document attachments…</p>;
  }

  if (!requirements.length && !(ticket.documents || []).length) {
    return <div className="empty">No documents are required for this request type.</div>;
  }

  return (
    <div className="ticket-docs-panel">
      {locked && (
        <p className="muted ticket-docs-lock-note">
          {ticket.status === "in_progress"
            ? "MVAS is handling this request. You cannot upload or remove documents until it is sent back for updates."
            : ticket.status === "completed"
              ? "This request is approved. Documents are locked."
              : ticket.status === "closed"
                ? "This request is closed. Documents are locked."
                : "Documents are locked for this request."}
        </p>
      )}
      {!locked && ticket.status === "rejected" && (
        <p className="alert" style={{ marginBottom: "0.75rem" }} role="status">
          This request was sent back. Update or replace the documents below so MVAS can re-check.
        </p>
      )}

      {errorMessage && <div className="alert">{errorMessage}</div>}

      {requirements.length > 0 ? (
        <>
          <p className="muted doc-preview-hint" style={{ marginBottom: "0.75rem" }}>
            {requiredIds.length
              ? `Files marked * are required (${requiredIds.length}). Optional files can be added later.`
              : "No required files for this request type — optional uploads only."}
          </p>
          <div className="upload-grid">
            {requirements.map((r) => {
              const uploaded = byType[r.document_type.id];
              const isOptional = !r.is_required || softOptional(r);
              return (
                <div
                  key={r.id}
                  className={`doc-slot${uploaded ? " is-done" : ""}${locked ? " is-locked" : ""}`}
                >
                  <label>
                    {r.document_type.name}
                    {!isOptional ? " *" : " (optional)"}
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
                      {!locked && (
                        <label className="linkish" style={{ cursor: busy ? "wait" : "pointer" }}>
                          Replace
                          <input
                            type="file"
                            accept={acceptAttrFromMimes(r.document_type.accepted_mimes)}
                            disabled={busy}
                            hidden
                            onChange={(e) => {
                              const file = e.target.files?.[0];
                              e.target.value = "";
                              onPick(r, file);
                            }}
                          />
                        </label>
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
        </>
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

      {mode === "wizard" && !locked && missingRequired.length > 0 && (
        <div className="alert" style={{ marginTop: "0.85rem" }} role="status">
          <strong>Still needed before you can finish:</strong>{" "}
          {missingRequired.map((r) => r.document_type.name).join(", ")}.
          <br />
          <span className="muted">
            Or choose “Upload later” to open the request and attach these from there.
          </span>
        </div>
      )}
      {mode === "wizard" && !locked && requiredIds.length > 0 && missingRequired.length === 0 && (
        <p className="muted" style={{ marginTop: "0.85rem", color: "var(--et-green)" }}>
          All required documents are uploaded. You can finish now.
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
