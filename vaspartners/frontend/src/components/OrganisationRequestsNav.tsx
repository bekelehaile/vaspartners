"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";

const items = [
  {
    href: "/portal/company-requests",
    label: "Company requests",
    hint: "What you submitted",
  },
  {
    href: "/portal/membership-requests",
    label: "Membership requests",
    hint: "Joins you approve",
  },
] as const;

/** Clear switcher so company vs membership requests never feel like one inbox. */
export function OrganisationRequestsNav() {
  const pathname = usePathname();

  return (
    <nav className="org-requests-nav" aria-label="Organisation requests">
      {items.map((item) => {
        const active = pathname === item.href || pathname.startsWith(`${item.href}/`);
        return (
          <Link
            key={item.href}
            href={item.href}
            className={active ? "is-active" : undefined}
            aria-current={active ? "page" : undefined}
          >
            <span className="org-requests-nav-label">{item.label}</span>
            <span className="org-requests-nav-hint">{item.hint}</span>
          </Link>
        );
      })}
    </nav>
  );
}
