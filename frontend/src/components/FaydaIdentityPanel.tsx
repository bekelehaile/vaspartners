"use client";

import type { ReactNode } from "react";

export type FaydaIdentityFields = {
  name?: string | null;
  phone_number?: string | null;
  email?: string | null;
  gender?: string | null;
  nationality?: string | null;
  birthdate?: string | null;
  identification_type?: string | null;
  identification_number?: string | null;
};

function formatBirthdate(value?: string | null): string {
  if (!value) return "—";
  const d = value.slice(0, 10);
  return d || value;
}

export function FaydaIdentityPanel({
  id = "fayda-identity",
  title = "Fayda identity",
  description = "Verified from National ID (Fayda) — read-only.",
  person,
  badge,
  footer,
}: {
  id?: string;
  title?: string;
  description?: string;
  person: FaydaIdentityFields;
  badge?: ReactNode;
  footer?: ReactNode;
}) {
  return (
    <section id={id} className="settings-block fayda-readonly">
      <div className="settings-block-head">
        <div className="settings-block-title-row">
          <h3>{title}</h3>
          {badge}
        </div>
        <p className="muted">{description}</p>
      </div>
      <dl className="fayda-dl">
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
