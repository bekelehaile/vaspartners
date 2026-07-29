"use client";

import { useMemo, useState } from "react";
import type { DocumentRequirement } from "@/lib/api";
import {
  acceptAttrFromMimes,
  validateFileAgainstDocType,
} from "@/lib/document-upload";
import { useDocumentRequirements } from "@/hooks/use-contact";

export type StagedAttachments = Record<number, File>;

function isSoftOptional(r: DocumentRequirement): boolean {
  if (!r.is_required) return true;
  if (r.document_type.code === "document-if-any") return true;
  return /if any/i.test(r.document_type.name);
}

/** Dynamic checklist + file pickers from admin service × request-type matrix. */
export function RequirementsPreview({
  serviceId,
  requisitionId,
  files,
  onFilesChange,
}: {
  serviceId: string;
  requisitionId: string;
  files: StagedAttachments;
  onFilesChange: (next: StagedAttachments) => void;
}) {
  const enabled = !!serviceId && !!requisitionId;
  const { data: requirements = [], isLoading, isError, error } =
    useDocumentRequirements(serviceId, requisitionId);
  const [localError, setLocalError] = useState<string | null>(null);

  const required = useMemo(
    () => requirements.filter((r) => !isSoftOptional(r)),
    [requirements]
  );
  const optional = useMemo(
    () => requirements.filter((r) => isSoftOptional(r)),
    [requirements]
  );

  function pick(req: DocumentRequirement, file: File | undefined) {
    setLocalError(null);
    if (!file) return;
    const err = validateFileAgainstDocType(file, req.document_type);
    if (err) {
      setLocalError(err);
      return;
    }
    onFilesChange({ ...files, [req.document_type.id]: file });
  }

  function clear(documentTypeId: number) {
    const next = { ...files };
    delete next[documentTypeId];
    onFilesChange(next);
  }

  if (!enabled) return null;

  if (isLoading) {
    return (
      <div className="field doc-preview">
        <label>Required documents</label>
        <p className="muted">Loading required documents…</p>
      </div>
    );
  }

  if (isError) {
    return (
      <div className="field doc-preview">
        <label>Required documents</label>
        <p className="alert" style={{ margin: 0 }}>
          {error instanceof Error
            ? error.message
            : "Could not load document requirements."}
        </p>
      </div>
    );
  }

  if (!requirements.length) {
    return (
      <div className="field doc-preview">
        <label>Required documents</label>
        <p className="muted">
          No documents are required for this service and request type.
        </p>
      </div>
    );
  }

  return (
    <div className="field doc-preview">
      <label>
        {required.length > 0
          ? `Required documents (${required.length})`
          : "Optional documents"}
      </label>
      {required.length > 0 && (
        <p className="muted" style={{ marginTop: "0.35rem" }}>
          Attach every file marked <strong>*</strong>, then submit. You can delete the
          request later if Ethio telecom has not started handling it yet.
        </p>
      )}

      {localError && <div className="alert">{localError}</div>}

      <div className="upload-grid" style={{ marginTop: "0.75rem" }}>
        {[...required, ...optional].map((r) => {
          const softOptional = isSoftOptional(r);
          const staged = files[r.document_type.id];
          return (
            <div
              key={r.id}
              className={`doc-slot${staged ? " is-done" : ""}${!softOptional && !staged ? " doc-slot-required" : ""}`}
            >
              <label>
                {r.document_type.name}
                {softOptional ? " (optional)" : " *"}
              </label>
              <small className="muted">
                {acceptAttrFromMimes(r.document_type.accepted_mimes)
                  .replaceAll(".", "")
                  .replaceAll(",", ", ")
                  .toUpperCase() || "—"}{" "}
                · max {r.document_type.max_size_kb} KB
              </small>
              {staged ? (
                <div className="doc-slot-actions">
                  <small style={{ color: "var(--primary)" }}>
                    Ready: {staged.name}
                  </small>
                  <button
                    type="button"
                    className="linkish"
                    onClick={() => clear(r.document_type.id)}
                  >
                    Remove
                  </button>
                  <label className="linkish" style={{ cursor: "pointer" }}>
                    Replace
                    <input
                      type="file"
                      accept={acceptAttrFromMimes(r.document_type.accepted_mimes)}
                      hidden
                      onChange={(e) => {
                        const file = e.target.files?.[0];
                        e.target.value = "";
                        pick(r, file);
                      }}
                    />
                  </label>
                </div>
              ) : (
                <input
                  type="file"
                  accept={acceptAttrFromMimes(r.document_type.accepted_mimes)}
                  onChange={(e) => {
                    const file = e.target.files?.[0];
                    e.target.value = "";
                    pick(r, file);
                  }}
                />
              )}
            </div>
          );
        })}
      </div>
    </div>
  );
}

/** True when every hard-required matrix document has a staged file. */
export function requiredAttachmentsReady(
  requirements: DocumentRequirement[],
  files: StagedAttachments
): boolean {
  const hardRequired = requirements.filter((r) => !isSoftOptional(r));
  if (!hardRequired.length) return true;
  return hardRequired.every((r) => !!files[r.document_type.id]);
}
