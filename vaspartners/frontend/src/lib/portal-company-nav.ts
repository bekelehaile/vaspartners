/** Shared company workspace section ids — keep Account, sidebar, and page headers aligned. */

export type CompanySectionId = "identity" | "profile" | "members" | "ownership";

export const COMPANY_SECTION_HASH: Record<CompanySectionId, string> = {
  identity: "fayda-identity",
  profile: "company-info",
  members: "company-members-panel",
  ownership: "leave-company",
};

export const COMPANY_HASH_TO_SECTION: Record<string, CompanySectionId> = {
  "fayda-identity": "identity",
  "fayda-identity-panel": "identity",
  "company-info": "profile",
  "company-profile-panel": "profile",
  "company-members-panel": "members",
  members: "members",
  "transfer-ownership": "ownership",
  "leave-company": "ownership",
  ownership: "ownership",
};

/** Short labels used in Account menu, sidebar, and vertical tabs. */
export const COMPANY_SECTION_LABEL: Record<CompanySectionId, string> = {
  identity: "Identity",
  profile: "Company",
  members: "Members",
  ownership: "Ownership",
};

export function companySectionHref(section: CompanySectionId): string {
  return `/portal/company#${COMPANY_SECTION_HASH[section]}`;
}

export function companySectionFromHash(hash?: string | null): CompanySectionId | null {
  const key = (hash ?? "").replace(/^#/, "").trim();
  if (!key) return null;
  return COMPANY_HASH_TO_SECTION[key] ?? null;
}

/** Same-page hash navigation that always fires hashchange for listeners. */
export function navigatePortalHref(href: string) {
  if (typeof window === "undefined") return;
  if (!href.includes("#")) {
    window.location.assign(href);
    return;
  }
  const [path, hash] = href.split("#");
  if (window.location.pathname === path) {
    const next = `#${hash}`;
    if (window.location.hash === next) {
      window.dispatchEvent(new HashChangeEvent("hashchange"));
      return;
    }
    window.location.hash = hash;
    return;
  }
  window.location.assign(href);
}
