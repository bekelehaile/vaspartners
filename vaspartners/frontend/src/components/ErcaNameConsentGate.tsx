"use client";

import { FormEvent, useEffect, useState } from "react";
import {
  useContact,
  useErcaCompanyPreview,
  useSubmitErcaNameConsent,
  useUpdateCompanyTinFromErca,
  type ErcaCompanyPreview,
} from "@/hooks/use-contact";
import { isValidEthiopianTin, normalizeEthiopianTin } from "@/lib/tin";

/**
 * When ERCA finds the TIN but the legal name ≠ entered company name,
 * partner must: use legal name, or search/update TIN via ERCA and confirm.
 */
export function ErcaNameConsentGate({ children }: { children: React.ReactNode }) {
  const { data: me } = useContact();
  const consent = useSubmitErcaNameConsent();
  const previewMut = useErcaCompanyPreview();
  const updateTin = useUpdateCompanyTinFromErca();

  const [mode, setMode] = useState<"choose" | "search">("choose");
  const [tin, setTin] = useState("");
  const [preview, setPreview] = useState<ErcaCompanyPreview | null>(null);
  const [localError, setLocalError] = useState<string | null>(null);

  const company = me?.company;
  const needsConsent =
    !!me?.profile_completed &&
    !!company &&
    company.needs_erca_name_consent === true;
  const canConsent = me?.company_role === "owner" || !!me?.company_can_edit;
  const busy =
    consent.isPending || previewMut.isPending || updateTin.isPending;

  useEffect(() => {
    if (!needsConsent) return;
    const previous = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    return () => {
      document.body.style.overflow = previous;
    };
  }, [needsConsent]);

  useEffect(() => {
    if (needsConsent && company?.tin && !tin) {
      setTin(company.tin);
    }
  }, [needsConsent, company?.tin, tin]);

  if (!needsConsent) {
    return <>{children}</>;
  }

  const entered = company?.name || "—";
  const legal = company?.legal_name || "—";

  const onAcceptLegal = () => {
    setLocalError(null);
    consent.mutate(
      { action: "use_legal" },
      {
        onError: (err) => {
          setLocalError(err instanceof Error ? err.message : "Could not save your choice.");
        },
      },
    );
  };

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

  const onConfirmTinUpdate = () => {
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
    <>
      {children}
      <div className="portal-modal-backdrop" role="presentation">
        <div
          className="portal-modal tin-gate-modal"
          role="dialog"
          aria-modal="true"
          aria-labelledby="erca-name-title"
          aria-describedby="erca-name-desc"
          onClick={(e) => e.stopPropagation()}
        >
          <h2 id="erca-name-title">Update company TIN / legal name</h2>
          <p id="erca-name-desc" className="muted">
            ERCA verification found a different legal name for your TIN. Accept the ERCA
            legal name, or search again with the correct TIN and confirm to update your
            company.
          </p>

          <div
            className="erca-name-compare"
            style={{ display: "grid", gap: "0.75rem", margin: "1rem 0" }}
          >
            <div>
              <p className="portal-modal-hint" style={{ marginBottom: "0.25rem" }}>
                Current company name
              </p>
              <p>
                <strong>{entered}</strong>
              </p>
              <p className="muted" style={{ marginTop: "0.25rem" }}>
                TIN: {company?.tin || "—"}
              </p>
            </div>
            <div>
              <p className="portal-modal-hint" style={{ marginBottom: "0.25rem" }}>
                ERCA legal name
              </p>
              <p>
                <strong>{legal}</strong>
              </p>
            </div>
          </div>

          {(localError || consent.isError || previewMut.isError || updateTin.isError) && (
            <p className="alert" role="alert">
              {localError ||
                (consent.error instanceof Error
                  ? consent.error.message
                  : previewMut.error instanceof Error
                    ? previewMut.error.message
                    : updateTin.error instanceof Error
                      ? updateTin.error.message
                      : "Something went wrong.")}
            </p>
          )}

          {!canConsent ? (
            <p className="portal-modal-hint">
              Ask your company owner to update the TIN or confirm the legal name.
            </p>
          ) : mode === "choose" ? (
            <div className="portal-modal-actions" style={{ flexWrap: "wrap" }}>
              <button
                type="button"
                className="btn-primary"
                disabled={busy}
                onClick={onAcceptLegal}
              >
                {consent.isPending ? "Saving…" : "Use ERCA legal name"}
              </button>
              <button
                type="button"
                className="btn-secondary"
                disabled={busy}
                onClick={() => {
                  setMode("search");
                  setLocalError(null);
                  setPreview(null);
                }}
              >
                Search / update TIN
              </button>
            </div>
          ) : (
            <div className="portal-stack-sm">
              {!preview ? (
                <form onSubmit={onSearch} className="portal-stack-sm" noValidate>
                  <div className="field">
                    <label htmlFor="erca-update-tin">
                      TIN <span className="req">*</span>
                    </label>
                    <input
                      id="erca-update-tin"
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
                    <button
                      type="button"
                      className="btn-ghost"
                      disabled={busy}
                      onClick={() => {
                        setMode("choose");
                        setPreview(null);
                        setLocalError(null);
                      }}
                    >
                      Back
                    </button>
                  </div>
                </form>
              ) : (
                <>
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
                    {preview.entity_type ? (
                      <div>
                        <dt>Entity type</dt>
                        <dd>{preview.entity_type}</dd>
                      </div>
                    ) : null}
                    {(preview.region || preview.city) && (
                      <div>
                        <dt>Location</dt>
                        <dd>
                          {[preview.city, preview.region].filter(Boolean).join(", ")}
                        </dd>
                      </div>
                    )}
                  </dl>
                  <div className="portal-modal-actions" style={{ flexWrap: "wrap" }}>
                    <button
                      type="button"
                      className="btn-primary"
                      disabled={busy}
                      onClick={onConfirmTinUpdate}
                    >
                      {updateTin.isPending
                        ? "Updating…"
                        : "Confirm and update company"}
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
                    <button
                      type="button"
                      className="btn-ghost"
                      disabled={busy}
                      onClick={() => {
                        setMode("choose");
                        setPreview(null);
                      }}
                    >
                      Back
                    </button>
                  </div>
                </>
              )}
            </div>
          )}

          <p className="portal-modal-hint" style={{ marginTop: "1rem" }}>
            Confirming updates your local company name and TIN from ERCA. This is required
            when the registry name does not match what you entered.
          </p>
        </div>
      </div>
    </>
  );
}
