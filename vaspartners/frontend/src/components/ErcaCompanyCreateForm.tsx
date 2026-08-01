"use client";

import { FormEvent, useState } from "react";
import { useRouter } from "next/navigation";
import {
  OnboardingProgress,
  SecureSessionNote,
  maskMobileDisplay,
} from "@/components/OnboardingProgress";
import { Contact } from "@/lib/api";
import { useAuthConfig } from "@/hooks/use-auth-config";
import {
  useDeclineErcaCompany,
  useErcaCompanyPreview,
  useCreateCompanyFromErca,
  type ErcaCompanyPreview,
} from "@/hooks/use-contact";
import { isValidEthiopianTin, normalizeEthiopianTin } from "@/lib/tin";

/**
 * New company: search ERCA by TIN → confirm registry → create, then CRM identity.
 */
export function ErcaCompanyCreateForm({
  me,
  redirectTo = "/portal",
}: {
  me?: Contact | null;
  redirectTo?: string;
}) {
  const router = useRouter();
  const { data: authConfig } = useAuthConfig();
  const ercaDown = authConfig?.erca_tin?.available === false;
  const ercaMessage =
    authConfig?.erca_tin?.message ||
    "TIN number verification is temporarily unavailable. Please try again shortly.";
  const previewMut = useErcaCompanyPreview();
  const createMut = useCreateCompanyFromErca();
  const declineMut = useDeclineErcaCompany();

  const [tin, setTin] = useState("");
  const [address, setAddress] = useState("");
  const [preview, setPreview] = useState<ErcaCompanyPreview | null>(null);
  const [localError, setLocalError] = useState<string | null>(null);

  const busy =
    previewMut.isPending || createMut.isPending || declineMut.isPending;

  const onSearch = (e: FormEvent) => {
    e.preventDefault();
    setLocalError(null);
    if (ercaDown) {
      setLocalError(ercaMessage);
      return;
    }
    const normalized = normalizeEthiopianTin(tin);
    if (!isValidEthiopianTin(normalized)) {
      setLocalError("Enter a valid 10-digit TIN number.");
      return;
    }
    previewMut.mutate(normalized, {
      onSuccess: (data) => {
        setPreview(data);
        setAddress((prev) => prev || (data.address ?? ""));
      },
      onError: (err) => {
        setPreview(null);
        setLocalError(err instanceof Error ? err.message : "TIN number lookup failed.");
      },
    });
  };

  const onConfirm = (e: FormEvent) => {
    e.preventDefault();
    if (!preview) return;
    setLocalError(null);
    if (address.trim().length < 5) {
      setLocalError("Enter the company address (at least 5 characters).");
      return;
    }
    createMut.mutate(
      { preview_token: preview.preview_token, company_address: address.trim() },
      {
        onSuccess: (res) => {
          const identity = res.identity;
          const contact = res.data;
          if (identity?.needs_consent && identity.proposal) {
            router.replace("/login");
            return;
          }
          if (identity?.needs_manual_name) {
            router.replace("/login");
            return;
          }
          router.replace(contact.profile_completed ? redirectTo : "/portal/company");
        },
        onError: (err) => {
          setLocalError(
            err instanceof Error ? err.message : "Could not create company.",
          );
        },
      },
    );
  };

  const onDecline = () => {
    setLocalError(null);
    declineMut.mutate(
      { preview_token: preview?.preview_token },
      {
        onSettled: () => {
          if (typeof window !== "undefined") {
            window.location.assign("/login");
          }
        },
      },
    );
  };

  return (
    <div className="panel company-form company-form--onboard">
      <OnboardingProgress current="company" />

      <div className="company-form-head">
        <p className="login-kicker">Step 2 · ERCA company verification</p>
        <h2>{preview ? "Confirm company details" : "Verify company TIN"}</h2>
        <p className="muted">
          {preview
            ? "Review the official ERCA registry record carefully. Confirm only if this is your organisation."
            : "Enter your 10-digit TIN. We look it up in ERCA before creating your company profile."}
        </p>
      </div>

      {me?.phone_number ? (
        <div className="onboard-session" role="status">
          <span>Signed in as</span>
          <strong>{maskMobileDisplay(me.phone_number)}</strong>
        </div>
      ) : null}

      {ercaDown && (
        <div className="alert" role="status">
          {ercaMessage}
        </div>
      )}

      {(localError || previewMut.isError || createMut.isError) && (
        <div className="alert" role="alert">
          {localError ||
            (previewMut.error instanceof Error
              ? previewMut.error.message
              : createMut.error instanceof Error
                ? createMut.error.message
                : "Something went wrong.")}
        </div>
      )}

      {!preview ? (
        <form onSubmit={onSearch} className="portal-stack-sm" noValidate autoComplete="off">
          <section className="settings-block">
            <div className="settings-block-head">
              <h3>TIN number</h3>
              <p className="muted">Official Ethiopian Tax Identification Number (10 digits).</p>
            </div>
            <div className="field">
              <label htmlFor="erca-tin">
                Company TIN <span className="req">*</span>
              </label>
              <input
                id="erca-tin"
                name="company_tin"
                inputMode="numeric"
                autoComplete="off"
                autoCorrect="off"
                spellCheck={false}
                maxLength={14}
                placeholder="10 digits"
                value={tin}
                onChange={(e) => setTin(e.target.value.replace(/[^\d\s-]/g, ""))}
                required
                disabled={busy || ercaDown}
                aria-describedby="erca-tin-help"
              />
              <p id="erca-tin-help" className="field-help">
                Lookup uses a secure government registry connection. Results are shown only to you.
              </p>
            </div>
          </section>
          <div className="form-actions">
            <button type="submit" className="btn-primary" disabled={busy || ercaDown}>
              {previewMut.isPending ? "Verifying with ERCA…" : "Search and verify TIN"}
            </button>
            <button type="button" className="btn-ghost" disabled={busy} onClick={onDecline}>
              Cancel and sign out
            </button>
          </div>
        </form>
      ) : (
        <form onSubmit={onConfirm} className="portal-stack-sm" noValidate>
          <section className="settings-block erca-confirm-panel">
            <div className="settings-block-head">
              <h3>ERCA registry record</h3>
              <p className="muted">Official limited extract — confirm before creating.</p>
            </div>
            <dl className="fayda-dl company-profile-dl">
              <div>
                <dt>TIN number</dt>
                <dd>
                  <strong>{preview.tin}</strong>
                </dd>
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
              {preview.address ? (
                <div>
                  <dt>ERCA address</dt>
                  <dd>{preview.address}</dd>
                </div>
              ) : null}
              {preview.phone ? (
                <div>
                  <dt>Registry phone</dt>
                  <dd>{preview.phone}</dd>
                </div>
              ) : null}
              {preview.email ? (
                <div>
                  <dt>Registry email</dt>
                  <dd>{preview.email}</dd>
                </div>
              ) : null}
            </dl>
          </section>

          <div className="field field-span">
            <label htmlFor="erca-address">
              Operating address <span className="req">*</span>
            </label>
            <textarea
              id="erca-address"
              name="company_address"
              rows={3}
              placeholder="Company operating / office address"
              value={address}
              onChange={(e) => setAddress(e.target.value)}
              required
              disabled={busy}
              aria-describedby="erca-address-help"
            />
            <p id="erca-address-help" className="field-help">
              Used on your VAS Partners company profile. You can refine it later if needed.
            </p>
          </div>

          <div className="form-actions" style={{ flexWrap: "wrap" }}>
            <button type="submit" className="btn-primary" disabled={busy}>
              {createMut.isPending ? "Creating secure company profile…" : "Confirm — this is my company"}
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
              Search another TIN
            </button>
            <button type="button" className="btn-ghost" disabled={busy} onClick={onDecline}>
              Not my company — sign out
            </button>
          </div>
        </form>
      )}

      <SecureSessionNote>
        Company data is verified with ERCA. Confirm only your own organisation.
      </SecureSessionNote>
    </div>
  );
}
