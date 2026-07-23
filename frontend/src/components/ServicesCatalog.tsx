"use client";

import { useMemo, useState } from "react";
import Link from "next/link";
import { useCustomer, useDocumentRequirements, useServices } from "@/hooks/use-customer";
import { faydaLoginUrl } from "@/lib/api";
import type { Service } from "@/lib/api";

type ServicesCatalogProps = {
  /** Extra class on the outer section */
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
  lead = "Browse the Ethio telecom VAS partners catalog. Select a service to see details, then request it for your approved company.",
  compact = false,
}: ServicesCatalogProps) {
  const { data: services = [], isLoading, isError, error } = useServices();
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
    [services],
  );

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
        <div className="landing-service-reqs">
          <h4>Description</h4>
          <p>
            {service.description?.trim() ||
              "Value added service available through the VAS Partners portal."}
          </p>
        </div>

        {open && (
          <div className="landing-service-reqs">
            <h4>Requirements</h4>
            <ServiceRequirements
              serviceId={service.id}
              requisitionIds={requisitions.map((r) => r.id)}
            />
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

function ServiceRequirements({
  serviceId,
  requisitionIds,
}: {
  serviceId: number;
  requisitionIds: number[];
}) {
  if (requisitionIds.length === 0) {
    return (
      <p className="muted" style={{ marginTop: "0.35rem" }}>
        No requirements configured for this service yet.
      </p>
    );
  }

  return (
    <MergedRequirementsList serviceId={serviceId} requisitionIds={requisitionIds} />
  );
}

function MergedRequirementsList({
  serviceId,
  requisitionIds,
}: {
  serviceId: number;
  requisitionIds: number[];
}) {
  const primaryId = requisitionIds[0]!;
  const { data: primary = [], isLoading, isError } = useDocumentRequirements(
    String(serviceId),
    String(primaryId),
  );
  const second = useDocumentRequirements(
    String(serviceId),
    String(requisitionIds[1] ?? ""),
  );
  const third = useDocumentRequirements(
    String(serviceId),
    String(requisitionIds[2] ?? ""),
  );
  const fourth = useDocumentRequirements(
    String(serviceId),
    String(requisitionIds[3] ?? ""),
  );
  const fifth = useDocumentRequirements(
    String(serviceId),
    String(requisitionIds[4] ?? ""),
  );

  const loading =
    isLoading ||
    (requisitionIds[1] && second.isLoading) ||
    (requisitionIds[2] && third.isLoading) ||
    (requisitionIds[3] && fourth.isLoading) ||
    (requisitionIds[4] && fifth.isLoading);

  const errored =
    isError ||
    (requisitionIds[1] && second.isError) ||
    (requisitionIds[2] && third.isError) ||
    (requisitionIds[3] && fourth.isError) ||
    (requisitionIds[4] && fifth.isError);

  const merged = useMemo(() => {
    const bags = [primary, second.data, third.data, fourth.data, fifth.data].filter(
      Boolean,
    ) as NonNullable<typeof primary>[];
    const byType = new Map<
      number,
      { id: number; name: string; required: boolean; description?: string | null }
    >();
    for (const bag of bags) {
      for (const req of bag ?? []) {
        const typeId = req.document_type.id;
        const existing = byType.get(typeId);
        if (!existing) {
          byType.set(typeId, {
            id: req.id,
            name: req.document_type.name,
            required: !!req.is_required,
            description: req.document_type.description,
          });
        } else if (req.is_required) {
          existing.required = true;
        }
      }
    }
    return Array.from(byType.values()).sort((a, b) => a.name.localeCompare(b.name));
  }, [primary, second.data, third.data, fourth.data, fifth.data]);

  if (loading) {
    return <p className="muted" style={{ marginTop: "0.35rem" }}>Loading requirements…</p>;
  }
  if (errored && merged.length === 0) {
    return (
      <p className="muted" style={{ marginTop: "0.35rem" }}>
        Could not load requirements for this service.
      </p>
    );
  }
  if (merged.length === 0) {
    return (
      <p className="muted" style={{ marginTop: "0.35rem" }}>
        No documents required for this service.
      </p>
    );
  }

  return (
    <ul style={{ marginTop: "0.5rem" }}>
      {merged.map((req) => (
        <li key={req.id}>
          <strong>{req.name}</strong>
          {req.required ? " (required)" : " (optional)"}
          {req.description?.trim() ? (
            <span className="muted"> — {req.description.trim()}</span>
          ) : null}
        </li>
      ))}
    </ul>
  );
}
