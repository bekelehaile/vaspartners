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
 * Blocks portal use for the current company until its TIN is approved.
 */
export function TinValidationGate({ children }: { children: React.ReactNode }) {
  const { data: me } = useContact();
  const submitTin = useSubmitCompanyTin();
  const switchCompany = useSwitchCompany();
  const [tin, setTin] = useState("");
  const [localError, setLocalError] = useState<string | null>(null);

  const company = me?.company;
  const needsGate =
    !!me?.profile_completed &&
    !!company &&
    !company.tin_validated &&
    company.needs_erca_name_consent !== true &&
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

  useEffect(() => {
    if (company?.tin && currentTinOk && !tin) {
      setTin(company.tin);
    }
  }, [company?.tin, currentTinOk, tin]);

  if (!needsGate) {
    return <>{children}</>;
  }

  const onSubmit = (e: FormEvent) => {
    e.preventDefault();
    setLocalError(null);
    const normalized = normalizeEthiopianTin(tin || company?.tin || "");
    if (!isValidEthiopianTin(normalized)) {
      setLocalError("Enter a 10-digit TIN.");
      return;
    }
    submitTin.mutate(
      { company_tin: normalized },
      {
        onError: (err) => {
          setLocalError(err instanceof Error ? err.message : "Could not save TIN.");
        },
      },
    );
  };

  const waiting = submitTin.isSuccess || (currentTinOk && !!company?.tin);

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
          <h2 id="tin-gate-title">Confirm company TIN</h2>
          <p id="tin-gate-desc" className="muted">
            {company?.name ? (
              <>
                <strong>{company.name}</strong> needs a confirmed tax number (TIN) before
                you can continue.
              </>
            ) : (
              <>This company needs a confirmed tax number (TIN) before you can continue.</>
            )}
          </p>

          {canSubmitTin ? (
            <form onSubmit={onSubmit} className="tin-gate-form">
              <label className="field" htmlFor="tin-gate-input">
                <span>TIN</span>
                <input
                  id="tin-gate-input"
                  name="company_tin"
                  inputMode="numeric"
                  autoComplete="off"
                  maxLength={14}
                  placeholder="10 digits"
                  value={tin}
                  onChange={(e) => setTin(e.target.value.replace(/[^\d\s-]/g, ""))}
                  required
                  aria-describedby="tin-gate-hint"
                />
              </label>
              <p id="tin-gate-hint" className="portal-modal-hint">
                Exactly 10 digits.
              </p>

              {(localError || submitTin.isError) && (
                <p className="alert" role="alert">
                  {localError ||
                    (submitTin.error instanceof Error
                      ? submitTin.error.message
                      : "Could not save TIN.")}
                </p>
              )}

              {waiting && !localError && !submitTin.isError && (
                <p className="alert alert-success" role="status">
                  Submitted. We will review it shortly.
                </p>
              )}

              <div className="portal-modal-actions">
                <button
                  type="submit"
                  className="btn-primary tin-gate-submit"
                  disabled={submitTin.isPending}
                >
                  {submitTin.isPending ? "Saving…" : waiting ? "Update TIN" : "Submit TIN"}
                </button>
                <Link href="/portal/company" className="btn-ghost">
                  Company settings
                </Link>
              </div>
            </form>
          ) : (
            <>
              <p className="portal-modal-hint">
                Ask your company owner to submit the TIN.
              </p>
              <div className="portal-modal-actions">
                <Link href="/portal/company" className="btn-ghost">
                  Company settings
                </Link>
              </div>
            </>
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
