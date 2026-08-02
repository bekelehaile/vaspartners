"use client";

import Link from "next/link";
import { useMemo } from "react";
import { useParams } from "next/navigation";
import { SiteShell } from "@/components/SiteShell";
import { ServiceRequirements } from "@/components/ServiceRequirements";
import { useContact, useLogout, useServices } from "@/hooks/use-contact";
import { portalLoginHref, useAuthConfig } from "@/hooks/use-auth-config";
import {
  descriptionLooksLikeHtml,
  polishServiceDescription,
  sanitizeServiceHtml,
  serviceDescriptionBlocks,
  serviceImageUrl,
  splitServiceDisplayName,
} from "@/lib/service-images";

export default function ServiceDetailPage() {
  const params = useParams<{ slug: string }>();
  const slug = params?.slug ?? "";
  const { data: me = null } = useContact();
  const logout = useLogout();
  const { data: authConfig } = useAuthConfig();
  const loginHref = portalLoginHref(authConfig);
  const loginExternal = loginHref.startsWith("http");
  const { data: services = [], isLoading, isError } = useServices();

  const service = useMemo(
    () => services.find((s) => s.slug === slug) ?? null,
    [services, slug],
  );

  const display = useMemo(
    () => splitServiceDisplayName(service?.name ?? "Service"),
    [service?.name],
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

  const ctaLabel = signedIn
    ? canRequest
      ? "Start a request"
      : "Complete company setup"
    : "Sign in to request";

  const rawDescription = service?.description ?? "";
  const looksHtml = descriptionLooksLikeHtml(rawDescription);
  const isLegacyCopy = /(?:^|[\s>])rn(?:[\s<]|$)|service:\s*-|Legal requirement to get/i.test(
    rawDescription,
  );
  const usePolishedPlain = !looksHtml || isLegacyCopy;

  const polished = useMemo(
    () => polishServiceDescription(rawDescription, service?.name),
    [rawDescription, service?.name],
  );
  const plainBlocks = useMemo(
    () => (usePolishedPlain ? serviceDescriptionBlocks(polished) : []),
    [usePolishedPlain, polished],
  );
  const htmlDescription =
    !usePolishedPlain && looksHtml ? sanitizeServiceHtml(rawDescription) : "";

  const imageSrc = service ? serviceImageUrl(service) : "/img/services.svg";
  const isSubscription = service?.is_subscription_based !== false;

  return (
    <SiteShell me={me} onLogout={() => void logout()} landing>
      <section className="section service-detail-page">
        <nav className="service-detail-crumb" aria-label="Breadcrumb">
          <Link href="/#services">Services</Link>
          <span className="service-detail-crumb-sep" aria-hidden>
            ›
          </span>
          <span aria-current="page">{display.title}</span>
        </nav>

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
          <article className="service-detail">
            <header className="service-detail-hero">
              <div className="service-detail-hero-media">
                {/* eslint-disable-next-line @next/next/no-img-element */}
                <img
                  src={imageSrc}
                  alt=""
                  width={560}
                  height={320}
                />
              </div>

              <div className="service-detail-hero-copy">
                {display.code ? (
                  <p className="service-detail-code">{display.code}</p>
                ) : null}
                <h1>{display.title}</h1>
                <div className="service-detail-meta">
                  <span className="service-detail-chip">
                    {isSubscription ? "Subscription based" : "One-off service"}
                  </span>
                  {service.renewal_interval && isSubscription ? (
                    <span className="service-detail-chip is-muted">
                      Renews {String(service.renewal_interval).replace(/_/g, " ")}
                    </span>
                  ) : null}
                </div>
                <div className="service-detail-hero-actions">
                  {signedIn ? (
                    <Link href={requestHref} className="btn-hero">
                      {ctaLabel}
                    </Link>
                  ) : loginExternal ? (
                    <a className="btn-hero" href={loginHref}>
                      {ctaLabel}
                    </a>
                  ) : (
                    <Link className="btn-hero" href={loginHref} prefetch={false}>
                      {ctaLabel}
                    </Link>
                  )}
                  <Link href="/#services" className="btn-hero-ghost">
                    All services
                  </Link>
                </div>
              </div>
            </header>

            <div className="service-detail-grid">
              <section className="service-detail-panel" aria-labelledby="service-about">
                <h2 id="service-about">About this service</h2>
                {usePolishedPlain ? (
                  <div className="service-rich-text">
                    {plainBlocks.map((block, i) => {
                      if (block.type === "h") {
                        return <h3 key={i}>{block.text}</h3>;
                      }
                      if (block.type === "ol") {
                        return (
                          <ol key={i}>
                            {block.items.map((item, j) => (
                              <li key={j}>{item}</li>
                            ))}
                          </ol>
                        );
                      }
                      return <p key={i}>{block.text}</p>;
                    })}
                  </div>
                ) : (
                  <div
                    className="service-rich-text"
                    dangerouslySetInnerHTML={{ __html: htmlDescription }}
                  />
                )}
              </section>

              <section
                className="service-detail-panel service-detail-panel--docs"
                aria-labelledby="service-docs"
              >
                <h2 id="service-docs">Documents you may need</h2>
                <p className="service-detail-panel-lead">
                  Exact requirements depend on the request type you choose after sign-in.
                </p>
                <ServiceRequirements
                  serviceId={service.id}
                  requisitionIds={(service.requisitions ?? []).map((r) => r.id)}
                />
              </section>
            </div>

            <footer className="service-detail-footer">
              <div>
                <p className="service-detail-footer-title">Ready to proceed?</p>
                <p className="muted">
                  Sign in to open a request for {display.title}. Ethio telecom will guide the approval steps.
                </p>
              </div>
              <div className="service-detail-footer-actions">
                {signedIn ? (
                  <Link href={requestHref} className="btn-hero">
                    {ctaLabel}
                  </Link>
                ) : loginExternal ? (
                  <a className="btn-hero" href={loginHref}>
                    {ctaLabel}
                  </a>
                ) : (
                  <Link className="btn-hero" href={loginHref} prefetch={false}>
                    {ctaLabel}
                  </Link>
                )}
              </div>
            </footer>
          </article>
        )}
      </section>
    </SiteShell>
  );
}
