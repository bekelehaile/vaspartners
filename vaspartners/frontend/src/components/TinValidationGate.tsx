"use client";

import { FormEvent, useEffect, useMemo, useState } from "react";
import Link from "next/link";
import {
  useContact,
  useSubmitCompanyTin,
  useSwitchCompany,
} from "@/hooks/use-contact";
import { isValidEthiopianTin, normalizeEthiopianTin } from "@/lib/tin";

/**
 * Blocks service use only for the *current* company when its TIN is not
 * admin-validated. Partners can switch to another company that already has
 * a validated TIN and continue working.
 */
export function TinValidationGate({ children }: { children: React.ReactNode }) {
  const { data: me } = useContact();
  const submitTin = useSubmitCompanyTin();
  const switchCompany = useSwitchCompany();
  const [tin, setTin] = useState("");
  const [localError, setLocalError] = useState<string | null>(null);

  const company = me?.company;
  const needsGate = !!me?.profile_completed && !!company && !company.tin_validated;
  const canSubmitTin = me?.company_role === "owner" || !!me?.company_can_edit;

  const otherValidated = useMemo(() => {
    return (me?.memberships ?? []).filter(
      (m) =>
        m.company_public_id &&
        m.is_active !== false &&
        !m.is_current &&
        m.tin_validated === true &&
        m.is_approved === true,
    );
  }, [me?.memberships]);

  const formatOk = useMemo(
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

  const onSubmit = (e: FormEvent) => {
    e.preventDefault();
    setLocalError(null);
    const normalized = normalizeEthiopianTin(tin || company?.tin || "");
    if (!isValidEthiopianTin(normalized)) {
      setLocalError("Enter a valid Ethiopian TIN: exactly 10 digits.");
      return;
    }
    submitTin.mutate(
      { company_tin: normalized },
      {
        onError: (err) => {
          setLocalError(err instanceof Error ? err.message : "Could not submit TIN");
        },
      },
    );
  };

  return (
    <>
      {children}
      <div className="portal-modal-backdrop" role="presentation">
        <div
          className="portal-modal"
          role="dialog"
          aria-modal="true"
          aria-labelledby="tin-gate-title"
          aria-describedby="tin-gate-desc"
          onClick={(e) => e.stopPropagation()}
        >
          <h2 id="tin-gate-title">This company needs TIN validation</h2>
          <p id="tin-gate-desc">
            <strong>{company?.name || "This company"}</strong> cannot use VAS services until
            Ethio telecom validates its TIN. Other companies with a validated TIN stay available
            — switch below to continue.
          </p>

          {otherValidated.length > 0 && (
            <div className="tin-gate-switch" style={{ marginBottom: "1rem" }}>
              <p className="portal-modal-hint" style={{ marginBottom: "0.5rem" }}>
                Switch to a company with a validated TIN:
              </p>
              <div style={{ display: "flex", flexDirection: "column", gap: "0.5rem" }}>
                {otherValidated.map((m) => (
                  <button
                    key={m.company_public_id!}
                    type="button"
                    className="btn-ghost"
                    disabled={switchCompany.isPending}
                    onClick={() =>
                      void switchCompany.mutateAsync(m.company_public_id!)
                    }
                  >
                    Use {m.company_name || "company"}
                    {m.company_tin ? ` (TIN ${m.company_tin})` : ""}
                  </button>
                ))}
              </div>
              {switchCompany.isError && (
                <p className="alert" role="alert" style={{ marginTop: "0.5rem" }}>
                  {switchCompany.error instanceof Error
                    ? switchCompany.error.message
                    : "Could not switch company"}
                </p>
              )}
            </div>
          )}

          <p className="portal-modal-hint">
            Or stay on this company and{" "}
            {formatOk && company?.tin ? (
              <>
                wait for validation of TIN <strong>{company.tin}</strong> (you can correct it
                below).
              </>
            ) : (
              <>submit a valid 10-digit Ethiopian TIN below.</>
            )}
          </p>

          {canSubmitTin ? (
            <form onSubmit={onSubmit} className="tin-gate-form">
              <label className="field" htmlFor="tin-gate-input">
                <span>Ethiopian TIN for {company?.name || "this company"}</span>
                <input
                  id="tin-gate-input"
                  name="company_tin"
                  inputMode="numeric"
                  autoComplete="off"
                  maxLength={14}
                  placeholder={company?.tin && formatOk ? company.tin : "0001234567"}
                  value={tin}
                  onChange={(e) => setTin(e.target.value.replace(/[^\d\s-]/g, ""))}
                  required
                />
              </label>
              {(localError || submitTin.isError) && (
                <p className="alert" role="alert">
                  {localError ||
                    (submitTin.error instanceof Error
                      ? submitTin.error.message
                      : "Could not submit TIN")}
                </p>
              )}
              {submitTin.isSuccess && (
                <p className="portal-modal-hint" role="status">
                  TIN submitted for this company. Waiting for Ethio telecom to validate it.
                </p>
              )}
              <div className="portal-modal-actions">
                <button
                  type="submit"
                  className="btn-primary tin-gate-submit"
                  disabled={submitTin.isPending}
                >
                  {submitTin.isPending ? "Submitting…" : "Submit TIN for this company"}
                </button>
                <Link href="/portal/company" className="btn-ghost">
                  Company settings
                </Link>
              </div>
            </form>
          ) : (
            <>
              <p className="portal-modal-hint">
                Ask the owner of this company to submit a valid TIN. Meanwhile, switch to another
                company with a validated TIN if you have one.
              </p>
              <div className="portal-modal-actions">
                <Link href="/portal/company" className="btn-ghost">
                  Company settings
                </Link>
              </div>
            </>
          )}
        </div>
      </div>
    </>
  );
}
