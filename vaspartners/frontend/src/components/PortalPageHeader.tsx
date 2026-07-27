"use client";

import Link from "next/link";
import { ReactNode } from "react";
import { useContact } from "@/hooks/use-contact";
import {
  contactCanCreateServiceRequests,
  contactCanCreateSubscriptions,
  contactCanManageServices,
} from "@/lib/company-permissions";

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
  const { data: me } = useContact();
  if (me && !contactCanCreateServiceRequests(me)) {
    return null;
  }

  return (
    <Link href="/portal/requests/new" className={className}>
      New service request
    </Link>
  );
}

/** Dual CTAs for the two partner journeys — gated separately. */
export function JourneyLaunchActions() {
  const { data: me } = useContact();
  const canSubscribe = !me || contactCanCreateSubscriptions(me);
  const canManage = !me || contactCanManageServices(me);

  if (me && !canSubscribe && !canManage) {
    return null;
  }

  return (
    <div className="journey-launch">
      {canSubscribe && (
        <Link href="/portal/requests/new?intent=subscribe" className="btn-primary">
          New subscription
        </Link>
      )}
      {canManage && (
        <Link href="/portal/requests/new?intent=manage" className="btn-ghost">
          Manage service
        </Link>
      )}
    </div>
  );
}
