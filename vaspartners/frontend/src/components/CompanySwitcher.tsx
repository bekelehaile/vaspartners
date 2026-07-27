"use client";

import type { Contact } from "@/lib/api";
import { useSwitchCompany } from "@/hooks/use-contact";

function membershipLabel(
  m: NonNullable<Contact["memberships"]>[number],
): string {
  const name = m.company_name || "Company";
  const role = m.role || "member";
  const bits = [name, role];
  if (m.approval_status && m.approval_status !== "approved") {
    bits.push(m.approval_status);
  }
  if (m.is_active === false) {
    bits.push("disabled");
  }
  return bits.join(" · ");
}

function currentCompanyName(me: Contact): string | null {
  const current = (me.memberships ?? []).find((m) => m.is_current && m.is_active !== false);
  return (
    current?.company_name ||
    me.company_name ||
    me.company?.name ||
    null
  );
}

export function CompanySwitcher({
  me,
  variant = "header",
  showHint = false,
}: {
  me: Contact;
  variant?: "header" | "page";
  showHint?: boolean;
}) {
  const switchCompany = useSwitchCompany();
  const memberships = me.memberships ?? [];
  const switchable = memberships.filter(
    (m) => m.company_public_id && m.is_active !== false,
  );

  const displayName = currentCompanyName(me);
  const canSwitch = switchable.length > 1;

  const currentId =
    switchable.find((m) => m.is_current)?.company_public_id ||
    switchable[0]?.company_public_id ||
    "";

  const onChange = (companyPublicId: string) => {
    if (!companyPublicId || companyPublicId === currentId) return;
    void switchCompany.mutateAsync(companyPublicId);
  };

  if (variant === "header") {
    if (switchable.length === 0 && !displayName) {
      return null;
    }

    return (
      <div className="company-switcher company-switcher-header">
        <label className="portal-logged-in-as" htmlFor="company-switch-header">
          <span className="portal-logged-in-label">Logged in as</span>
          {switchable.length > 0 ? (
            <span className="portal-logged-in-switch">
              <select
                id="company-switch-header"
                className="company-switcher-select company-switcher-select-header"
                value={currentId}
                disabled={switchCompany.isPending || !canSwitch}
                aria-label="Switch company"
                title={
                  canSwitch
                    ? "Switch active company"
                    : "Create or join another company to switch"
                }
                onChange={(e) => onChange(e.target.value)}
              >
                {switchable.map((m) => (
                  <option key={m.company_public_id!} value={m.company_public_id!}>
                    {m.company_name || "Company"}
                    {m.role ? ` · ${m.role}` : ""}
                    {m.approval_status && m.approval_status !== "approved"
                      ? ` · ${m.approval_status}`
                      : ""}
                  </option>
                ))}
              </select>
              {canSwitch && (
                <span className="portal-logged-in-switch-hint" aria-hidden>
                  Switch
                </span>
              )}
            </span>
          ) : (
            <strong className="portal-logged-in-company" title={displayName || undefined}>
              {displayName || "Company"}
            </strong>
          )}
        </label>
        {switchCompany.isPending && (
          <span className="muted company-switcher-hint">Switching…</span>
        )}
        {switchCompany.isError && (
          <span className="alert company-switcher-error" role="alert">
            {switchCompany.error instanceof Error
              ? switchCompany.error.message
              : "Could not switch company"}
          </span>
        )}
      </div>
    );
  }

  if (switchable.length === 0) {
    return null;
  }

  return (
    <div className="company-switcher company-switcher-page">
      <label htmlFor="company-switch-page" className="company-switcher-label">
        Active company
      </label>
      <select
        id="company-switch-page"
        className="company-switcher-select"
        value={currentId}
        disabled={switchCompany.isPending || !canSwitch}
        aria-label="Switch company"
        onChange={(e) => onChange(e.target.value)}
      >
        {switchable.map((m) => (
          <option key={m.company_public_id!} value={m.company_public_id!}>
            {membershipLabel(m)}
            {m.is_current ? " (current)" : ""}
          </option>
        ))}
      </select>
      {!canSwitch && showHint && (
        <p className="muted company-switcher-hint">
          You only have one active company. Create or join another to switch.
        </p>
      )}
      {switchCompany.isPending && (
        <p className="muted company-switcher-hint">Switching…</p>
      )}
      {switchCompany.isError && (
        <p className="alert company-switcher-error" role="alert">
          {switchCompany.error instanceof Error
            ? switchCompany.error.message
            : "Could not switch company"}
        </p>
      )}
    </div>
  );
}
