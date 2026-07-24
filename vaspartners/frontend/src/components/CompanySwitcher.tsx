"use client";

import type { Customer } from "@/lib/api";
import { useSwitchCompany } from "@/hooks/use-customer";

function membershipLabel(
  m: NonNullable<Customer["memberships"]>[number],
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

export function CompanySwitcher({
  me,
  variant = "header",
  showHint = false,
}: {
  me: Customer;
  variant?: "header" | "page";
  showHint?: boolean;
}) {
  const switchCompany = useSwitchCompany();
  const memberships = me.memberships ?? [];
  const switchable = memberships.filter(
    (m) => m.company_public_id && m.is_active !== false,
  );

  if (switchable.length === 0) {
    return null;
  }

  const currentId =
    switchable.find((m) => m.is_current)?.company_public_id ||
    switchable[0]?.company_public_id ||
    "";

  const onChange = (companyPublicId: string) => {
    if (!companyPublicId || companyPublicId === currentId) return;
    void switchCompany.mutateAsync(companyPublicId);
  };

  return (
    <div
      className={
        variant === "header"
          ? "company-switcher company-switcher-header"
          : "company-switcher company-switcher-page"
      }
    >
      <label htmlFor={`company-switch-${variant}`} className="company-switcher-label">
        {variant === "header" ? "Company" : "Active company"}
      </label>
      <select
        id={`company-switch-${variant}`}
        className="company-switcher-select"
        value={currentId}
        disabled={switchCompany.isPending || switchable.length < 2}
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
      {switchable.length < 2 && showHint && (
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
