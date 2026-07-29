"use client";

import { FormEvent, useMemo, useState } from "react";
import { useContact, useSubmitCompanyTin } from "@/hooks/use-contact";
import { isValidEthiopianTin, normalizeEthiopianTin } from "@/lib/tin";

/**
 * Blocks service-request flows until the current company has an admin-validated TIN.
 * Partners with edit permission can submit / correct a 10-digit Ethiopian TIN here.
 */
export function TinValidationGate({ children }: { children: React.ReactNode }) {
  const { data: me } = useContact();
  const submitTin = useSubmitCompanyTin();
  const [tin, setTin] = useState("");
  const [localError, setLocalError] = useState<string | null>(null);

  const company = me?.company;
  const needsGate = !!me?.profile_completed && !!company && !company.tin_validated;
  const canSubmitTin = me?.company_role === "owner" || !!me?.company_can_edit;

  const formatOk = useMemo(
    () => (company?.tin ? isValidEthiopianTin(company.tin) : false),
    [company?.tin],
  );

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
          <h2 id="tin-gate-title">Company TIN required</h2>
          <p id="tin-gate-desc">
            Before you can submit service requests, your company must have a valid Ethiopian TIN
            that Ethio telecom has validated.
          </p>

          {formatOk && company?.tin ? (
            <p className="portal-modal-hint">
              Current TIN <strong>{company.tin}</strong> is awaiting admin validation. You can
              correct it below if needed.
            </p>
          ) : (
            <p className="portal-modal-hint">
              Enter your 10-digit Ministry of Revenues (ERCA) TIN. Placeholders like MVAS codes
              are not accepted.
            </p>
          )}

          {canSubmitTin ? (
            <form onSubmit={onSubmit} className="tin-gate-form">
              <label className="field" htmlFor="tin-gate-input">
                <span>Ethiopian TIN</span>
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
                  TIN submitted. Waiting for Ethio telecom to validate it.
                </p>
              )}
              <div className="portal-modal-actions">
                <button type="submit" className="btn" disabled={submitTin.isPending}>
                  {submitTin.isPending ? "Submitting…" : "Submit TIN"}
                </button>
              </div>
            </form>
          ) : (
            <p className="portal-modal-hint">
              Ask your company owner to submit a valid TIN. Service requests stay locked until
              Ethio telecom validates it.
            </p>
          )}
        </div>
      </div>
    </>
  );
}
