"use client";

import { useEffect, useState } from "react";
import { useContact, useSubmitErcaNameConsent } from "@/hooks/use-contact";

/**
 * When ERCA finds the TIN but the legal name ≠ entered company name,
 * partner must choose: use legal name, or keep both.
 */
export function ErcaNameConsentGate({ children }: { children: React.ReactNode }) {
  const { data: me } = useContact();
  const consent = useSubmitErcaNameConsent();
  const [localError, setLocalError] = useState<string | null>(null);

  const company = me?.company;
  const needsConsent =
    !!me?.profile_completed &&
    !!company &&
    company.needs_erca_name_consent === true;
  const canConsent = me?.company_role === "owner" || !!me?.company_can_edit;

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

  const onAction = (action: "use_legal" | "keep_both") => {
    setLocalError(null);
    consent.mutate(
      { action },
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
          <h2 id="erca-name-title">Confirm company legal name</h2>
          <p id="erca-name-desc" className="muted">
            Your TIN was found in ERCA verification, but the name you entered does not match
            the registry legal name. Choose how to continue.
          </p>

          <div className="erca-name-compare" style={{ display: "grid", gap: "0.75rem", margin: "1rem 0" }}>
            <div>
              <p className="portal-modal-hint" style={{ marginBottom: "0.25rem" }}>
                You entered
              </p>
              <p>
                <strong>{entered}</strong>
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

          {(localError || consent.isError) && (
            <p className="alert" role="alert">
              {localError ||
                (consent.error instanceof Error
                  ? consent.error.message
                  : "Could not save your choice.")}
            </p>
          )}

          {canConsent ? (
            <div className="portal-modal-actions" style={{ flexWrap: "wrap" }}>
              <button
                type="button"
                className="btn-primary"
                disabled={consent.isPending}
                onClick={() => onAction("use_legal")}
              >
                {consent.isPending ? "Saving…" : "Use ERCA legal name"}
              </button>
              <button
                type="button"
                className="btn-secondary"
                disabled={consent.isPending}
                onClick={() => onAction("keep_both")}
              >
                Keep both names
              </button>
            </div>
          ) : (
            <p className="portal-modal-hint">
              Ask your company owner to confirm the legal name before you continue.
            </p>
          )}

          <p className="portal-modal-hint" style={{ marginTop: "1rem" }}>
            “Use ERCA legal name” replaces the company display name. “Keep both” keeps your
            entered name and stores the legal name for admin reference.
          </p>
        </div>
      </div>
    </>
  );
}
