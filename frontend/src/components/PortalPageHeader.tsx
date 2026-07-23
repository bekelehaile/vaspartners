"use client";

import Link from "next/link";
import { ReactNode } from "react";

export function PortalPageHeader({
  kicker,
  title,
  description,
  actions,
}: {
  kicker?: ReactNode;
  title: ReactNode;
  description?: ReactNode;
  actions?: ReactNode;
}) {
  return (
    <div className="portal-hero portal-page-header">
      <div className="portal-page-header-copy">
        {kicker && <p className="brand-kicker">{kicker}</p>}
        <h1>{title}</h1>
        {description && <p className="muted">{description}</p>}
      </div>
      {actions && <div className="portal-page-header-actions">{actions}</div>}
    </div>
  );
}

export function NewRequestButton({
  className = "btn-primary",
}: {
  className?: string;
}) {
  return (
    <Link href="/portal/requests/new" className={className}>
      New service request
    </Link>
  );
}
