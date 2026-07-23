"use client";

import { useMemo, useState } from "react";
import Link from "next/link";
import { useCustomer, useServices } from "@/hooks/use-customer";
import { faydaLoginUrl } from "@/lib/api";
import type { Service } from "@/lib/api";

export function LandingServicesSection() {
  const { data: services = [], isLoading, isError } = useServices();
  const { data: me } = useCustomer();
  const [openId, setOpenId] = useState<number | null>(null);
  const signedIn = !!me;
  const canRequest = !!me?.profile_completed;

  const rows = useMemo(
    () =>
      [...services].sort((a, b) => {
        const ca = a.category?.name || "";
        const cb = b.category?.name || "";
        if (ca !== cb) return ca.localeCompare(cb);
        return a.name.localeCompare(b.name);
      }),
    [services]
  );

  return (
    <section className="section" id="services">
      <span className="section-label">Our services</span>
      <h2>Comprehensive VAS solutions</h2>
      <p className="section-lead">
        Browse the Ethio telecom VAS partners catalog. Service requests require an
        administrator-approved company TIN.
      </p>

      {isLoading && (
        <div className="landing-services-loading" aria-busy>
          <span className="spinner" aria-hidden />
          <p className="muted">Loading services…</p>
        </div>
      )}

      {isError && (
        <div className="alert">Unable to load services right now. Please try again shortly.</div>
      )}

      {!isLoading && !isError && (
        <ul className="landing-service-list">
          {rows.map((service, index) => (
            <ServiceRow
              key={service.id}
              service={service}
              index={index}
              open={openId === service.id}
              signedIn={signedIn}
              canRequest={canRequest}
              onToggle={() => setOpenId((id) => (id === service.id ? null : service.id))}
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

function ServiceRow({
  service,
  index,
  open,
  signedIn,
  canRequest,
  onToggle,
}: {
  service: Service;
  index: number;
  open: boolean;
  signedIn: boolean;
  canRequest: boolean;
  onToggle: () => void;
}) {
  const requisitions = service.requisitions ?? [];
  const panelId = `service-detail-${service.id}`;
  const requestHref = canRequest
    ? `/portal/requests/new?intent=${
        service.is_subscription_based === false ? "manage" : "subscribe"
      }&service=${service.id}`
    : "/portal/company";

  return (
    <li
      className={`landing-service-item${open ? " is-open" : ""}`}
      style={{ animationDelay: `${Math.min(index, 12) * 0.045}s` }}
    >
      <button
        type="button"
        className="landing-service-trigger"
        aria-expanded={open}
        aria-controls={panelId}
        onClick={onToggle}
      >
        <span className="landing-service-main">
          <strong>{service.name}</strong>
          <span className="landing-service-meta">
            {service.category?.name && <span>{service.category.name}</span>}
            {service.type && <span className="service-meta">{service.type}</span>}
          </span>
        </span>
        <span className="landing-service-chevron" aria-hidden>
          {open ? "−" : "+"}
        </span>
      </button>

      <div
        id={panelId}
        className="landing-service-detail"
        role="region"
        hidden={!open}
      >
        <p>
          {service.description?.trim() ||
            "Value added service available through the VAS Partners portal."}
        </p>

        {requisitions.length > 0 && (
          <div className="landing-service-reqs">
            <h4>Request types</h4>
            <ul>
              {requisitions.map((r) => (
                <li key={r.id}>{r.name}</li>
              ))}
            </ul>
          </div>
        )}

        <div className="landing-service-actions">
          {signedIn ? (
            <Link
              href={requestHref}
              className="btn-primary"
              style={{ padding: "0.55rem 1rem" }}
            >
              {canRequest ? "Request this service" : "Complete company approval first"}
            </Link>
          ) : (
            <a className="btn-primary" href={faydaLoginUrl()} style={{ padding: "0.55rem 1rem" }}>
              Sign in to request
            </a>
          )}
          <button type="button" className="linkish" onClick={onToggle}>
            Close details
          </button>
        </div>
      </div>
    </li>
  );
}
