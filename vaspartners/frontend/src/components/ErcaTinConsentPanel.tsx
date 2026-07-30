"use client";

import { FormEvent, useState } from "react";
import {
  useErcaCompanyPreview,
  useUpdateCompanyTinFromErca,
  type ErcaCompanyPreview,
} from "@/hooks/use-contact";
import { isValidEthiopianTin, normalizeEthiopianTin } from "@/lib/tin";

/**
 * Shared ERCA TIN search → limited registry preview → partner consent.
 * Same pattern as new-company create, for updating an existing company’s TIN.
 */
export function ErcaTinConsentPanel({
  initialTin = "",
  onBack,
  confirmLabel = "Confirm and update company",
  searchHint = "Search ERCA by TIN. Confirm the registry details to update this company.",
}: {
  initialTin?: string;
  onBack?: () => void;
  confirmLabel?: string;
  searchHint?: string;
}) {
  const previewMut = useErcaCompanyPreview();
  const updateTin = useUpdateCompanyTinFromErca();

  const [tin, setTin] = useState(initialTin);
  const [preview, setPreview] = useState<ErcaCompanyPreview | null>(null);
  const [localError, setLocalError] = useState<string | null>(null);

  const busy = previewMut.isPending || updateTin.isPending;

  const onSearch = (e: FormEvent) => {
    e.preventDefault();
    setLocalError(null);
    const normalized = normalizeEthiopianTin(tin);
    if (!isValidEthiopianTin(normalized)) {
      setLocalError("Enter a valid 10-digit TIN.");
      return;
    }
    previewMut.mutate(normalized, {
      onSuccess: (data) => setPreview(data),
      onError: (err) => {
        setPreview(null);
        setLocalError(err instanceof Error ? err.message : "TIN lookup failed.");
      },
    });
  };

  const onConfirm = (e: FormEvent) => {
    e.preventDefault();
    if (!preview) return;
    setLocalError(null);
    updateTin.mutate(
      { preview_token: preview.preview_token },
      {
        onError: (err) => {
          setLocalError(
            err instanceof Error ? err.message : "Could not update company TIN.",
          );
        },
      },
    );
  };

  return (
    <div className="portal-stack-sm">
      {(localError || previewMut.isError || updateTin.isError) && (
        <p className="alert" role="alert">
          {localError ||
            (previewMut.error instanceof Error
              ? previewMut.error.message
              : updateTin.error instanceof Error
                ? updateTin.error.message
                : "Something went wrong.")}
        </p>
      )}

      {!preview ? (
        <form onSubmit={onSearch} className="portal-stack-sm" noValidate>
          <p className="portal-modal-hint">{searchHint}</p>
          <div className="field">
            <label htmlFor="erca-consent-tin">
              TIN <span className="req">*</span>
            </label>
            <input
              id="erca-consent-tin"
              inputMode="numeric"
              autoComplete="off"
              maxLength={14}
              placeholder="10 digits"
              value={tin}
              onChange={(e) => setTin(e.target.value.replace(/[^\d\s-]/g, ""))}
              required
              disabled={busy}
            />
          </div>
          <div className="portal-modal-actions" style={{ flexWrap: "wrap" }}>
            <button type="submit" className="btn-primary" disabled={busy}>
              {previewMut.isPending ? "Searching…" : "Search ERCA"}
            </button>
            {onBack ? (
              <button
                type="button"
                className="btn-ghost"
                disabled={busy}
                onClick={onBack}
              >
                Back
              </button>
            ) : null}
          </div>
        </form>
      ) : (
        <form onSubmit={onConfirm} className="portal-stack-sm" noValidate>
          <p className="portal-modal-hint">
            Confirm this is your company before applying the ERCA TIN and legal name.
          </p>
          <dl className="fayda-dl company-profile-dl">
            <div>
              <dt>TIN</dt>
              <dd>{preview.tin}</dd>
            </div>
            <div>
              <dt>Legal name</dt>
              <dd>
                <strong>{preview.legal_name}</strong>
              </dd>
            </div>
            {preview.business_name ? (
              <div>
                <dt>Business name</dt>
                <dd>{preview.business_name}</dd>
              </div>
            ) : null}
            {preview.entity_type ? (
              <div>
                <dt>Entity type</dt>
                <dd>{preview.entity_type}</dd>
              </div>
            ) : null}
            {preview.tax_centre ? (
              <div>
                <dt>Tax centre</dt>
                <dd>{preview.tax_centre}</dd>
              </div>
            ) : null}
            {(preview.region || preview.city) && (
              <div>
                <dt>Location</dt>
                <dd>
                  {[preview.city, preview.region].filter(Boolean).join(", ") || "—"}
                </dd>
              </div>
            )}
          </dl>
          <div className="portal-modal-actions" style={{ flexWrap: "wrap" }}>
            <button type="submit" className="btn-primary" disabled={busy}>
              {updateTin.isPending ? "Updating…" : confirmLabel}
            </button>
            <button
              type="button"
              className="btn-secondary"
              disabled={busy}
              onClick={() => {
                setPreview(null);
                setLocalError(null);
              }}
            >
              Search again
            </button>
            {onBack ? (
              <button
                type="button"
                className="btn-ghost"
                disabled={busy}
                onClick={() => {
                  setPreview(null);
                  setLocalError(null);
                  onBack();
                }}
              >
                Back
              </button>
            ) : null}
          </div>
        </form>
      )}
    </div>
  );
}
