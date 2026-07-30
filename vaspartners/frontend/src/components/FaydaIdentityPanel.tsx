"use client";

import type { ReactNode } from "react";

export type IdentityPersonFields = {
  name?: string | null;
  phone_number?: string | null;
  email?: string | null;
  gender?: string | null;
  nationality?: string | null;
  birthdate?: string | null;
  identification_type?: string | null;
  identification_number?: string | null;
  identity_verified_via?: string | null;
  identity_verified_at?: string | null;
  fayda_verified?: boolean | null;
};

function formatBirthdate(value?: string | null): string {
  if (!value) return "—";
  const d = value.slice(0, 10);
  return d || value;
}

function formatVerifiedAt(value?: string | null): string {
  if (!value) return "—";
  try {
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) return value;
    return d.toLocaleString();
  } catch {
    return value;
  }
}

export function identityViaLabel(via?: string | null, legacyFayda?: boolean | null): string {
  const v = (via || (legacyFayda ? "fayda" : "")).toLowerCase();
  if (v === "fayda") return "Fayda";
  if (v === "crm") return "CRM";
  return "—";
}

export function isPersonIdentityVerified(person: IdentityPersonFields): boolean {
  return Boolean(person.identity_verified_via || person.fayda_verified);
}

/** @deprecated Use IdentityPersonFields */
export type FaydaIdentityFields = IdentityPersonFields;

export function FaydaIdentityPanel({
  id = "partner-identity",
  title = "Your identity",
  description = "Identity details — read-only.",
  person,
  badge,
  footer,
  showHeading = true,
  showVerificationMeta = true,
}: {
  id?: string;
  title?: string;
  description?: string | null;
  person: IdentityPersonFields;
  badge?: ReactNode;
  footer?: ReactNode;
  /** When false, page header owns the title — avoid duplicate headings. */
  showHeading?: boolean;
  /** Show common Verified via / Verified at rows. */
  showVerificationMeta?: boolean;
}) {
  const via = identityViaLabel(person.identity_verified_via, person.fayda_verified);

  return (
    <section id={id} className="settings-block fayda-readonly">
      {showHeading && (
        <div className="settings-block-head">
          <div className="settings-block-title-row">
            <h3>{title}</h3>
            {badge}
          </div>
          {description && <p className="muted">{description}</p>}
        </div>
      )}
      {!showHeading && (badge || description) && (
        <div className="settings-block-head" style={{ marginBottom: "0.75rem" }}>
          {badge && <div className="settings-block-title-row">{badge}</div>}
          {description && <p className="muted">{description}</p>}
        </div>
      )}
      <dl className="fayda-dl">
        {showVerificationMeta ? (
          <>
            <div>
              <dt>Verified via</dt>
              <dd>{via}</dd>
            </div>
            <div>
              <dt>Verified at</dt>
              <dd>{formatVerifiedAt(person.identity_verified_at)}</dd>
            </div>
          </>
        ) : null}
        <div>
          <dt>Full name</dt>
          <dd>{person.name || "—"}</dd>
        </div>
        <div>
          <dt>Phone</dt>
          <dd>{person.phone_number || "—"}</dd>
        </div>
        <div>
          <dt>Email</dt>
          <dd>{person.email || "—"}</dd>
        </div>
        <div>
          <dt>ID type</dt>
          <dd>{person.identification_type || "—"}</dd>
        </div>
        <div>
          <dt>ID number</dt>
          <dd>{person.identification_number || "—"}</dd>
        </div>
        <div>
          <dt>Gender</dt>
          <dd>{person.gender || "—"}</dd>
        </div>
        <div>
          <dt>Nationality</dt>
          <dd>{person.nationality || "—"}</dd>
        </div>
        <div>
          <dt>Birthdate</dt>
          <dd>{formatBirthdate(person.birthdate)}</dd>
        </div>
      </dl>
      {footer}
    </section>
  );
}
