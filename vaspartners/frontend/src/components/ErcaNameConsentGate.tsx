"use client";

import { useEffect, useState } from "react";
import {
  useContact,
  useSubmitErcaNameConsent,
} from "@/hooks/use-contact";
import { ErcaTinConsentPanel } from "@/components/ErcaTinConsentPanel";

/**
 * ERCA name gates:
 * - mismatch: keep both / use legal / update TIN number
 * - name missing: partner enters company name so services are not blocked
 */
export function ErcaNameConsentGate({ children }: { children: React.ReactNode }) {
  const { data: me } = useContact();
  const consent = useSubmitErcaNameConsent();

  const [mode, setMode] = useState<"choose" | "search">("choose");
  const [localError, setLocalError] = useState<string | null>(null);
  const [enteredName, setEnteredName] = useState("");

  const company = me?.company;
  const needsMismatchConsent =
    !!me?.profile_completed &&
    !!company &&
    company.needs_erca_name_consent === true;
  const needsNameEntry =
    !!me?.profile_completed &&
    !!company &&
    company.needs_erca_name_entry === true &&
    company.needs_erca_name_consent !== true;
  const needsGate = needsMismatchConsent || needsNameEntry;
  const canConsent =
    me?.company_role === "owner" ||
    !!me?.company_can_edit ||
    (me?.company_permissions ?? []).includes("edit_company_profile");
  const busy = consent.isPending;

  useEffect(() => {
    if (!needsNameEntry) return;
    setEnteredName(company?.name?.trim() || "");
  }, [needsNameEntry, company?.name, company?.public_id]);

  useEffect(() => {
    if (!needsGate) return;
    const previous = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    return () => {
      document.body.style.overflow = previous;
    };
  }, [needsGate]);

  if (!needsGate) {
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

  const onProvideName = () => {
    const name = enteredName.trim();
    if (!name) {
      setLocalError("Enter your company name to continue.");
      return;
    }
    setLocalError(null);
    consent.mutate(
      { action: "provide_name", company_name: name },
      {
        onError: (err) => {
          setLocalError(err instanceof Error ? err.message : "Could not save the company name.");
        },
      },
    );
  };

  if (needsNameEntry) {
    return (
      <>
        {children}
        <div className="portal-modal-backdrop" role="presentation">
          <div
            className="portal-modal tin-gate-modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="erca-name-entry-title"
            aria-describedby="erca-name-entry-desc"
            onClick={(e) => e.stopPropagation()}
          >
            <h2 id="erca-name-entry-title">Enter company name</h2>
            <p id="erca-name-entry-desc" className="muted">
              Your TIN number is verified in ERCA, but ERCA did not return a legal name. Enter your
              company name to unlock portal services.
            </p>
            <p className="muted" style={{ marginTop: "0.5rem" }}>
              TIN number: {company?.tin || "—"}
            </p>

            {(localError || consent.isError) && (
              <p className="alert" role="alert">
                {localError ||
                  (consent.error instanceof Error
                    ? consent.error.message
                    : "Something went wrong.")}
              </p>
            )}

            {!canConsent ? (
              <p className="portal-modal-hint">
                Ask your company owner to enter the company name.
              </p>
            ) : (
              <>
                <label htmlFor="erca-partner-company-name" style={{ display: "block", marginTop: "1rem" }}>
                  Company name <span className="req">*</span>
                </label>
                <input
                  id="erca-partner-company-name"
                  type="text"
                  value={enteredName}
                  maxLength={255}
                  disabled={busy}
                  onChange={(e) => setEnteredName(e.target.value)}
                  placeholder="Enter company name"
                  style={{ width: "100%", marginTop: "0.35rem" }}
                />
                <div className="portal-modal-actions" style={{ marginTop: "1rem" }}>
                  <button
                    type="button"
                    className="btn-primary"
                    disabled={busy || !enteredName.trim()}
                    onClick={onProvideName}
                  >
                    {consent.isPending ? "Saving…" : "Save and continue"}
                  </button>
                </div>
              </>
            )}
          </div>
        </div>
      </>
    );
  }

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
            {mode === "search" ? "Update company TIN number" : "Confirm company name"}
          </h2>
          <p id="erca-name-desc" className="muted">
            {mode === "search" ? (
              <>
                Search ERCA for the correct TIN number, then confirm the registry details — same as
                creating a new company TIN number.
              </>
            ) : (
              <>
                Your TIN number is valid in ERCA, but the legal name differs. Keep both names, use the
                ERCA legal name, or update the TIN number.
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
                  TIN number: {company?.tin || "—"}
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
              Ask your company owner to confirm or update the TIN number.
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
                Update TIN number
              </button>
            </div>
          ) : (
            <ErcaTinConsentPanel
              initialTin={company?.tin || ""}
              onBack={() => setMode("choose")}
              confirmLabel="Confirm and update company"
              searchHint="Enter the TIN number, fetch from ERCA, then consent to apply it — same as a new company TIN number."
            />
          )}

          {mode === "choose" && (
            <p className="portal-modal-hint" style={{ marginTop: "1rem" }}>
              Keep both stores the ERCA legal name for reference. Use ERCA legal name replaces
              your display name. Update TIN number re-searches ERCA and requires consent.
            </p>
          )}
        </div>
      </div>
    </>
  );
}
