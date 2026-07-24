"use client";

import { useMemo, useState } from "react";
import Link from "next/link";
import { useCustomer, useServices } from "@/hooks/use-customer";
import { faydaLoginUrl } from "@/lib/api";
import type { Service } from "@/lib/api";
import { ServiceRequirements } from "@/components/ServiceRequirements";
import {
  formatServiceDescription,
  serviceImageUrl,
  sortServicesForLanding,
} from "@/lib/service-images";

type ServicesCatalogProps = {
  className?: string;
  heading?: string;
  kicker?: string;
  lead?: string;
  /** When true, omit the large marketing heading (portal uses its own page header). */
  compact?: boolean;
};

export function ServicesCatalog({
  className,
  heading = "Comprehensive VAS solutions",
  kicker = "Our services",
  lead = "Browse the Ethio telecom VAS partners catalog. Open a service for details and criteria, then request it for your approved company.",
  compact = false,
}: ServicesCatalogProps) {
  const { data: services = [], isLoading, isError, error } = useServices();
  const { data: me } = useCustomer();
  const signedIn = !!me;
  const canRequest = !!me?.profile_completed;

  const rows = useMemo(() => sortServicesForLanding(services), [services]);

  return (
    <section className={className || "section"} id={compact ? undefined : "services"}>
      {!compact && (
        <>
          <span className="section-label">{kicker}</span>
          <h2>{heading}</h2>
          <p className="section-lead">{lead}</p>
        </>
      )}

      {isLoading && (
        <div className="landing-services-loading" aria-busy>
          <span className="spinner" aria-hidden />
          <p className="muted">Loading services…</p>
        </div>
      )}

      {isError && (
        <div className="alert">
          {error instanceof Error
            ? error.message
            : "Unable to load services right now. Please try again shortly."}
        </div>
      )}

      {!isLoading && !isError && rows.length > 0 && (
        <ul className="service-card-grid">
          {rows.map((service, index) => (
            <PortalServiceCard
              key={service.id}
              service={service}
              index={index}
              signedIn={signedIn}
              canRequest={canRequest}
            />
          ))}
        </ul>
      )}

      {!isLoading && !isError && !rows.length && (
        <p className="muted">Services will appear here once the catalog is published.</p>
      )}
    </section>
  );
}

function PortalServiceCard({
  service,
  index,
  signedIn,
  canRequest,
}: {
  service: Service;
  index: number;
  signedIn: boolean;
  canRequest: boolean;
}) {
  const [open, setOpen] = useState(false);
  const requisitions = service.requisitions ?? [];
  const panelId = `service-detail-${service.id}`;
  const requestHref = canRequest
    ? `/portal/requests/new?intent=${
        service.is_subscription_based === false ? "manage" : "subscribe"
      }&service=${service.id}`
    : "/portal/company";
  const blurb = formatServiceDescription(service.description).split(/\n+/)[0];

  return (
    <li
      className={`service-card${open ? " is-open" : ""}`}
      style={{ animationDelay: `${Math.min(index, 17) * 0.04}s` }}
    >
      <Link href={`/services/${service.slug}`} className="service-card-link">
        <span className="service-card-media">
          {/* eslint-disable-next-line @next/next/no-img-element */}
          <img
            src={serviceImageUrl(service.slug)}
            alt=""
            width={320}
            height={160}
            loading={index < 6 ? "eager" : "lazy"}
          />
        </span>
        <span className="service-card-body">
          <strong>{service.name}</strong>
          <span className="service-card-cta">View details →</span>
        </span>
      </Link>

      <div className="service-card-footer">
        <button
          type="button"
          className="service-card-more"
          aria-expanded={open}
          aria-controls={panelId}
          onClick={() => setOpen((v) => !v)}
        >
          {open ? "Hide criteria" : "Quick criteria"}
        </button>
        {signedIn ? (
          <Link href={requestHref} className="service-card-request">
            {canRequest ? "Request" : "Company first"}
          </Link>
        ) : (
          <a className="service-card-request" href={faydaLoginUrl()}>
            Sign in
          </a>
        )}
      </div>

      <div id={panelId} className="service-card-panel" hidden={!open}>
        <p className="service-card-blurb">{blurb}</p>
        <h4>Requirements</h4>
        <ServiceRequirements
          serviceId={service.id}
          requisitionIds={requisitions.map((r) => r.id)}
        />
      </div>
    </li>
  );
}
