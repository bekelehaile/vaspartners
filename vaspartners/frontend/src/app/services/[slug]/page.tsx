"use client";

import Link from "next/link";
import { useMemo } from "react";
import { useParams } from "next/navigation";
import { SiteShell } from "@/components/SiteShell";
import { ServiceRequirements } from "@/components/ServiceRequirements";
import { useCustomer, useLogout, useServices } from "@/hooks/use-customer";
import { faydaLoginUrl } from "@/lib/api";
import {
  formatServiceDescription,
  serviceImageUrl,
} from "@/lib/service-images";

export default function ServiceDetailPage() {
  const params = useParams<{ slug: string }>();
  const slug = params?.slug ?? "";
  const { data: me = null } = useCustomer();
  const logout = useLogout();
  const { data: services = [], isLoading, isError } = useServices();

  const service = useMemo(
    () => services.find((s) => s.slug === slug) ?? null,
    [services, slug],
  );

  const signedIn = !!me;
  const canRequest = !!me?.profile_completed;
  const requestHref = service
    ? canRequest
      ? `/portal/requests/new?intent=${
          service.is_subscription_based === false ? "manage" : "subscribe"
        }&service=${service.id}`
      : "/portal/company"
    : "/";

  const description = formatServiceDescription(service?.description);
  const descriptionBlocks = description.split(/\n+/).filter(Boolean);

  return (
    <SiteShell me={me} onLogout={() => void logout()} landing>
      <section className="section service-detail-page">
        <p className="service-detail-crumb">
          <Link href="/#services">Services</Link>
          <span aria-hidden> / </span>
          <span>{service?.name || "Details"}</span>
        </p>

        {isLoading && (
          <div className="landing-services-loading" aria-busy>
            <span className="spinner" aria-hidden />
            <p className="muted">Loading service…</p>
          </div>
        )}

        {isError && (
          <div className="alert">Unable to load this service right now.</div>
        )}

        {!isLoading && !service && !isError && (
          <div className="alert">
            Service not found.{" "}
            <Link href="/#services">Back to services</Link>
          </div>
        )}

        {service && (
          <div className="service-detail-layout">
            <div className="service-detail-visual">
              {/* eslint-disable-next-line @next/next/no-img-element */}
              <img
                src={serviceImageUrl(service.slug)}
                alt=""
                width={480}
                height={280}
              />
            </div>

            <div className="service-detail-copy">
              <h1>{service.name}</h1>

              <div className="service-detail-body">
                <h2>Description</h2>
                {descriptionBlocks.map((block, i) => (
                  <p key={i}>{block}</p>
                ))}
              </div>

              <div className="service-detail-body">
                <h2>Document criteria</h2>
                <ServiceRequirements
                  serviceId={service.id}
                  requisitionIds={(service.requisitions ?? []).map((r) => r.id)}
                />
              </div>

              <div className="service-detail-actions">
                {signedIn ? (
                  <Link href={requestHref} className="btn-hero">
                    {canRequest
                      ? "Get started"
                      : "Complete company approval first"}
                  </Link>
                ) : (
                  <a className="btn-hero" href={faydaLoginUrl()}>
                    Get started
                  </a>
                )}
                <Link href="/#services" className="btn-hero-ghost">
                  Back to services
                </Link>
              </div>
            </div>
          </div>
        )}
      </section>
    </SiteShell>
  );
}
