"use client";

import type { Contact } from "@/lib/api";
import { useSwitchCompany } from "@/hooks/use-contact";
import { cn } from "@/lib/utils";

function currentCompanyName(me: Contact): string | null {
  const current = (me.memberships ?? []).find(
    (m) => m.is_current && m.is_active !== false,
  );
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

    if (switchable.length === 0) {
      return (
        <div
          className="company-switcher company-switcher-header"
          title={displayName || undefined}
        >
          <span className="company-switcher-static">{displayName}</span>
        </div>
      );
    }

    return (
      <div className="company-switcher company-switcher-header">
        <label className="sr-only" htmlFor="company-switch-header">
          Active company
        </label>
        <select
          id="company-switch-header"
          className={cn(
            "company-switcher-select company-switcher-select-header",
            !canSwitch && "is-readonly",
          )}
          value={currentId}
          disabled={switchCompany.isPending || !canSwitch}
          aria-label="Active company"
          title={canSwitch ? "Switch company" : displayName || "Active company"}
          onChange={(e) => onChange(e.target.value)}
        >
          {switchable.map((m) => (
            <option key={m.company_public_id!} value={m.company_public_id!}>
              {m.company_name || "Company"}
              {m.tin_validated ? " · TIN number verified" : " · Not verified"}
            </option>
          ))}
        </select>
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
            {[
              m.company_name || "Company",
              m.role,
              m.tin_validated ? "TIN number verified" : "Not verified",
              m.is_current ? "current" : null,
            ]
              .filter(Boolean)
              .join(" · ")}
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
