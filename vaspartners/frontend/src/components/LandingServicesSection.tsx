"use client";

import Link from "next/link";
import { useMemo } from "react";
import { useServices } from "@/hooks/use-customer";
import { serviceImageUrl, sortServicesForLanding } from "@/lib/service-images";

/**
 * Homepage services — navigable image grid (legacy portal pattern).
 */
export function LandingServicesSection() {
  const { data: services = [], isLoading, isError, error } = useServices();
  const rows = useMemo(() => sortServicesForLanding(services), [services]);

  return (
    <section className="section section-services" id="services" aria-labelledby="services-heading">
      <header className="section-head">
        <span className="section-label">Catalogue</span>
        <h2 id="services-heading">Value Added Services</h2>
        <p className="section-lead">
          Select a service to view description and document criteria. Sign in to submit a request.
        </p>
      </header>

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
            <li key={service.id} className="service-card">
              <Link
                href={`/services/${service.slug}`}
                className="service-card-link"
                aria-label={`${service.name} — view details`}
              >
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
                  <span className="service-card-cta">Details</span>
                </span>
              </Link>
            </li>
          ))}
        </ul>
      )}

      {!isLoading && !isError && !rows.length && (
        <p className="muted">Services will appear here once the catalogue is published.</p>
      )}
    </section>
  );
}
