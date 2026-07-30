"use client";

import { useEffect, useState } from "react";
import {
  useContact,
  useSubmitErcaNameConsent,
} from "@/hooks/use-contact";
import { ErcaTinConsentPanel } from "@/components/ErcaTinConsentPanel";

/**
 * When ERCA finds the TIN but the legal name ≠ entered company name,
 * partner must: keep both names, use legal name, or search/update TIN via ERCA consent.
 */
export function ErcaNameConsentGate({ children }: { children: React.ReactNode }) {
  const { data: me } = useContact();
  const consent = useSubmitErcaNameConsent();

  const [mode, setMode] = useState<"choose" | "search">("choose");
  const [localError, setLocalError] = useState<string | null>(null);

  const company = me?.company;
  const needsConsent =
    !!me?.profile_completed &&
    !!company &&
    company.needs_erca_name_consent === true;
  const canConsent = me?.company_role === "owner" || !!me?.company_can_edit;
  const busy = consent.isPending;

  useEffect(() => {
    if (!needsConsent) return;
    const previous = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    return () => {
      document.body.style.overflow = previous;
    };
  }, [needsConsent]);

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
          <h2 id="erca-name-title">
            {mode === "search" ? "Update company TIN" : "Confirm company name"}
          </h2>
          <p id="erca-name-desc" className="muted">
            {mode === "search" ? (
              <>
                Search ERCA for the correct TIN, then confirm the registry details — same as
                creating a new company TIN.
              </>
            ) : (
              <>
                Your TIN is valid in ERCA, but the legal name differs. Keep both names, use the
                ERCA legal name, or update the TIN.
              </>
            )}
          </p>

          {mode === "choose" && (
            <div
              className="erca-name-compare"
              style={{ display: "grid", gap: "0.75rem", margin: "1rem 0" }}
            >
              <div>
                <p className="portal-modal-hint" style={{ marginBottom: "0.25rem" }}>
                  Your company name
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
          )}

          {(localError || consent.isError) && mode === "choose" && (
            <p className="alert" role="alert">
              {localError ||
                (consent.error instanceof Error
                  ? consent.error.message
                  : "Something went wrong.")}
            </p>
          )}

          {!canConsent ? (
            <p className="portal-modal-hint">
              Ask your company owner to confirm or update the TIN.
            </p>
          ) : mode === "choose" ? (
            <div className="portal-modal-actions" style={{ flexWrap: "wrap" }}>
              <button
                type="button"
                className="btn-primary"
                disabled={busy}
                onClick={() => {
                  setLocalError(null);
                  consent.mutate(
                    { action: "keep_both" },
                    {
                      onError: (err) => {
                        setLocalError(
                          err instanceof Error ? err.message : "Could not save your choice.",
                        );
                      },
                    },
                  );
                }}
              >
                {consent.isPending ? "Saving…" : "Keep both names"}
              </button>
              <button
                type="button"
                className="btn-secondary"
                disabled={busy}
                onClick={onAcceptLegal}
              >
                Use ERCA legal name
              </button>
              <button
                type="button"
                className="btn-ghost"
                disabled={busy}
                onClick={() => {
                  setMode("search");
                  setLocalError(null);
                }}
              >
                Update TIN
              </button>
            </div>
          ) : (
            <ErcaTinConsentPanel
              initialTin={company?.tin || ""}
              onBack={() => setMode("choose")}
              confirmLabel="Confirm and update company"
              searchHint="Enter the TIN, fetch from ERCA, then consent to apply it — same as a new company TIN."
            />
          )}

          {mode === "choose" && (
            <p className="portal-modal-hint" style={{ marginTop: "1rem" }}>
              Keep both stores the ERCA legal name for reference. Use ERCA legal name replaces
              your display name. Update TIN re-searches ERCA and requires consent.
            </p>
          )}
        </div>
      </div>
    </>
  );
}
