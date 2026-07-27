"use client";

import { useState } from "react";
import type { DocumentRequirement, Ticket } from "@/lib/api";
import { getToken } from "@/lib/api";
import {
  acceptAttrFromMimes,
  documentsLockedStatus,
  validateFileAgainstDocType,
} from "@/lib/document-upload";
import {
  useDeleteTicketDocument,
  useDocumentRequirements,
  useUploadTicketDocument,
} from "@/hooks/use-contact";

type UploadedSlot = {
  id: number;
  name: string;
  downloadUrl?: string | null;
};

async function downloadTicketDocument(doc: {
  id: number;
  name: string;
  downloadUrl?: string | null;
}, ttNumber: string) {
  const url =
    doc.downloadUrl ||
    `${process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000/api/v1"}/tickets/${ttNumber}/documents/${doc.id}/download`;
  const token = getToken();
  const res = await fetch(url, {
    headers: {
      Accept: "application/octet-stream",
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
  });
  if (!res.ok) throw new Error("Could not download file");
  const blob = await res.blob();
  const objectUrl = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = objectUrl;
  a.download = doc.name || "document";
  a.click();
  URL.revokeObjectURL(objectUrl);
}

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
  const upload = useUploadTicketDocument(ticket.tt_number);
  const remove = useDeleteTicketDocument(ticket.tt_number);
  const [localError, setLocalError] = useState<string | null>(null);
  const [downloadingId, setDownloadingId] = useState<number | null>(null);

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

  const busy = upload.isPending || remove.isPending || downloadingId !== null;

  function onPick(req: DocumentRequirement, file: File | undefined) {
    setLocalError(null);
    if (!file) return;
    const err = validateFileAgainstDocType(file, req.document_type);
    if (err) {
      setLocalError(err);
      return;
    }
    upload.mutate({ documentTypeId: req.document_type.id, file });
  }

  function onRemove(documentId: number) {
    setLocalError(null);
    remove.mutate(documentId);
  }

  async function onDownload(doc: UploadedSlot) {
    setLocalError(null);
    setDownloadingId(doc.id);
    try {
      await downloadTicketDocument(doc, ticket.tt_number);
    } catch {
      setLocalError("Could not download this file. Try again.");
    } finally {
      setDownloadingId(null);
    }
  }

  if (isLoading) {
    return <p className="muted">Loading document attachments…</p>;
  }

  if (!requirements.length && !(ticket.documents || []).length) {
    return <div className="empty">No documents are required for this request type.</div>;
  }

  return (
    <div className="ticket-docs">
      {locked && (
        <div className="alert" role="status">
          {ticket.status === "in_progress" || ticket.status === "open"
            ? "MVAS is handling this request. You cannot upload or remove documents until it is sent back for updates. You can still download files you already uploaded."
            : "Documents are locked for this request status. You can still download uploaded files."}
        </div>
      )}
      {!locked && ticket.status === "rejected" && (
        <div className="alert" role="status">
          This request was sent back. Update or replace the documents below so MVAS can re-check.
        </div>
      )}
      {(localError || upload.isError || remove.isError) && (
        <div className="alert" role="alert">
          {localError ||
            (upload.error instanceof Error
              ? upload.error.message
              : remove.error instanceof Error
                ? remove.error.message
                : "Could not update documents")}
        </div>
      )}

      {requirements.length ? (
        <>
          <div className="ticket-docs-grid">
            {requirements.map((r) => {
              const uploaded = byType[r.document_type.id];
              const optional = !r.is_required || softOptional(r);
              return (
                <div key={r.id} className="ticket-doc-row">
                  <div>
                    <strong>
                      {r.document_type.name}
                      {optional ? (
                        <span className="muted"> (optional)</span>
                      ) : (
                        <span className="req"> *</span>
                      )}
                    </strong>
                    <small className="muted">
                      {acceptAttrFromMimes(r.document_type.accepted_mimes)
                        ? `Accepted: ${acceptAttrFromMimes(r.document_type.accepted_mimes)}`
                        : "Accepted: files per admin rules"}{" "}
                      · max {r.document_type.max_size_kb} KB
                    </small>
                  </div>
                  {uploaded ? (
                    <div className="ticket-doc-actions">
                      <span className="muted">{uploaded.name}</span>
                      <button
                        type="button"
                        className="linkish"
                        disabled={busy}
                        onClick={() => void onDownload(uploaded)}
                      >
                        {downloadingId === uploaded.id ? "Downloading…" : "Download"}
                      </button>
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
              <button
                type="button"
                className="linkish"
                disabled={busy}
                onClick={() =>
                  void onDownload({
                    id: d.id,
                    name: d.original_name,
                    downloadUrl: d.download_url,
                  })
                }
              >
                {downloadingId === d.id ? "Downloading…" : "Download"}
              </button>
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
        <p className="muted" style={{ marginTop: "0.85rem", color: "var(--primary)" }}>
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
    map[typeId] = {
      id: d.id,
      name: d.original_name,
      downloadUrl: d.download_url,
    };
  }
  return map;
}
