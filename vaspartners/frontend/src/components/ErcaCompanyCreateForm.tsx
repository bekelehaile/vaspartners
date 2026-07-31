"use client";

import { FormEvent, useState } from "react";
import { useRouter } from "next/navigation";
import { FaydaIdentityPanel } from "@/components/FaydaIdentityPanel";
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
 * New company: search ERCA by TIN number → limited registry info → consent to create, or logout.
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
        onSuccess: () => {
          router.replace(redirectTo);
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
    <div className="panel company-form">
      <div className="company-form-head">
        <h2>Create company</h2>
        <p className="muted">
          Search your TIN number in ERCA. Confirm the registry details to create your company, or
          sign out if this is not your organisation.
        </p>
      </div>

      {me && (
        <FaydaIdentityPanel
          id="fayda-identity"
          title="Your identity"
          description="Read-only."
          person={me}
          badge={
            me.company_role === "owner" ? (
              <span className="service-meta">Owner</span>
            ) : null
          }
        />
      )}

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
        <form onSubmit={onSearch} className="portal-stack-sm" noValidate>
          <section className="settings-block">
            <div className="settings-block-head">
              <h3>ERCA TIN number search</h3>
            </div>
            <div className="field">
              <label htmlFor="erca-tin">
                TIN number <span className="req">*</span>
              </label>
              <input
                id="erca-tin"
                name="company_tin"
                inputMode="numeric"
                autoComplete="off"
                maxLength={14}
                placeholder="10 digits"
                value={tin}
                onChange={(e) => setTin(e.target.value.replace(/[^\d\s-]/g, ""))}
                required
                disabled={busy || ercaDown}
              />
            </div>
          </section>
          <div className="form-actions">
            <button type="submit" className="btn-primary" disabled={busy || ercaDown}>
              {previewMut.isPending ? "Searching…" : "Search ERCA"}
            </button>
            <button
              type="button"
              className="btn-ghost"
              disabled={busy}
              onClick={onDecline}
            >
              Cancel and sign out
            </button>
          </div>
        </form>
      ) : (
        <form onSubmit={onConfirm} className="portal-stack-sm" noValidate>
          <section className="settings-block">
            <div className="settings-block-head">
              <h3>ERCA registry (limited)</h3>
              <p className="muted">Confirm this is your company before creating.</p>
            </div>
            <dl className="fayda-dl company-profile-dl">
              <div>
                <dt>TIN number</dt>
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
              {preview.address ? (
                <div>
                  <dt>ERCA address</dt>
                  <dd>{preview.address}</dd>
                </div>
              ) : null}
              {preview.phone ? (
                <div>
                  <dt>Phone</dt>
                  <dd>{preview.phone}</dd>
                </div>
              ) : null}
              {preview.email ? (
                <div>
                  <dt>Email</dt>
                  <dd>{preview.email}</dd>
                </div>
              ) : null}
            </dl>
          </section>

          <div className="field field-span">
            <label htmlFor="erca-address">
              Company address <span className="req">*</span>
            </label>
            <textarea
              id="erca-address"
              name="company_address"
              rows={3}
              placeholder="Operating / office address"
              value={address}
              onChange={(e) => setAddress(e.target.value)}
              required
              disabled={busy}
            />
          </div>

          <div className="form-actions" style={{ flexWrap: "wrap" }}>
            <button type="submit" className="btn-primary" disabled={busy}>
              {createMut.isPending ? "Creating…" : "Confirm and create company"}
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
              onClick={onDecline}
            >
              Not my company — sign out
            </button>
          </div>
        </form>
      )}
    </div>
  );
}
