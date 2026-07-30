"use client";

import { useEffect, useMemo } from "react";
import Link from "next/link";
import { useContact, useSwitchCompany } from "@/hooks/use-contact";
import { ErcaTinConsentPanel } from "@/components/ErcaTinConsentPanel";
import { isValidEthiopianTin } from "@/lib/tin";

/**
 * Blocks portal use until TIN number is confirmed via ERCA search + partner consent
 * (same flow as new company TIN number).
 */
export function TinValidationGate({ children }: { children: React.ReactNode }) {
  const { data: me } = useContact();
  const switchCompany = useSwitchCompany();

  const company = me?.company;
  const needsGate =
    !!me?.profile_completed &&
    !!company &&
    !company.tin_validated &&
    company.needs_erca_name_consent !== true &&
    company.needs_erca_name_entry !== true &&
    company.erca_identity_locked !== true;
  const canSubmitTin =
    (me?.company_role === "owner" || !!me?.company_can_edit) &&
    company?.erca_identity_locked !== true;

  const otherReady = useMemo(() => {
    return (me?.memberships ?? []).filter(
      (m) =>
        m.company_public_id &&
        m.is_active !== false &&
        !m.is_current &&
        m.tin_validated === true &&
        m.is_approved === true,
    );
  }, [me?.memberships]);

  const currentTinOk = useMemo(
    () => (company?.tin ? isValidEthiopianTin(company.tin) : false),
    [company?.tin],
  );

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

  return (
    <>
      {children}
      <div className="portal-modal-backdrop" role="presentation">
        <div
          className="portal-modal tin-gate-modal"
          role="dialog"
          aria-modal="true"
          aria-labelledby="tin-gate-title"
          aria-describedby="tin-gate-desc"
          onClick={(e) => e.stopPropagation()}
        >
          <h2 id="tin-gate-title">Confirm company TIN number</h2>
          <p id="tin-gate-desc" className="muted">
            {company?.name ? (
              <>
                <strong>{company.name}</strong> needs a valid TIN number from ERCA before you can use
                portal services. Search, review the limited registry details, then consent.
              </>
            ) : (
              <>
                This company needs a valid TIN number from ERCA before you can use portal services.
                Search, review the limited registry details, then consent.
              </>
            )}
          </p>

          {canSubmitTin ? (
            <ErcaTinConsentPanel
              initialTin={currentTinOk ? company?.tin || "" : ""}
              confirmLabel="Confirm and apply TIN number"
              searchHint="Search ERCA by TIN number. Confirm the registry details to unlock services — same as a new company TIN number."
            />
          ) : (
            <>
              <p className="portal-modal-hint">
                Ask your company owner to confirm the TIN number with ERCA.
              </p>
              <div className="portal-modal-actions">
                <Link href="/portal/company" className="btn-ghost">
                  Company settings
                </Link>
              </div>
            </>
          )}

          {canSubmitTin && (
            <div className="portal-modal-actions" style={{ marginTop: "0.75rem" }}>
              <Link href="/portal/company" className="btn-ghost">
                Company settings
              </Link>
            </div>
          )}

          {otherReady.length > 0 && (
            <div className="tin-gate-switch">
              <p className="portal-modal-hint">Or continue with another company:</p>
              <div className="tin-gate-switch-list">
                {otherReady.map((m) => (
                  <button
                    key={m.company_public_id!}
                    type="button"
                    className="btn-secondary"
                    disabled={switchCompany.isPending}
                    onClick={() => void switchCompany.mutateAsync(m.company_public_id!)}
                  >
                    {m.company_name || "Company"}
                    {m.company_tin ? ` · ${m.company_tin}` : ""}
                  </button>
                ))}
              </div>
              {switchCompany.isError && (
                <p className="alert" role="alert">
                  {switchCompany.error instanceof Error
                    ? switchCompany.error.message
                    : "Could not switch company."}
                </p>
              )}
            </div>
          )}
        </div>
      </div>
    </>
  );
}
