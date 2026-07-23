"use client";

import Link from "next/link";
import { StatusPill } from "@/components/StatusJourney";
import { useCustomer, useTickets } from "@/hooks/use-customer";

export default function PortalHomePage() {
  const { data: me } = useCustomer();
  const { data: tickets = [], isError, error } = useTickets();

  return (
    <>
      <div className="portal-hero">
        <p className="brand-kicker">Partner portal</p>
        <h1>Hello{me?.name ? `, ${me.name.split(" ")[0]}` : ""}</h1>
        <p className="muted">
          Signed in with Fayda. Manage VAS partner requests for{" "}
          <strong style={{ color: "var(--et-ink)" }}>{me?.company_name}</strong>.
        </p>
      </div>

      {isError && (
        <div className="section" style={{ paddingTop: 0 }}>
          <div className="alert">{error instanceof Error ? error.message : "Unable to load portal"}</div>
        </div>
      )}

      <div className="portal-grid">
        <section className="panel">
          <div
            style={{
              display: "flex",
              justifyContent: "space-between",
              gap: "1rem",
              alignItems: "center",
            }}
          >
            <h2 style={{ margin: 0 }}>Recent requests</h2>
            <Link href="/portal/requests/new" className="btn-primary" style={{ padding: "0.55rem 1rem" }}>
              New request
            </Link>
          </div>
          {!tickets.length ? (
            <div className="empty">
              You have no requests yet. When you are ready, start one — we will walk you through the
              documents.
            </div>
          ) : (
            tickets.slice(0, 5).map((t) => (
              <Link key={t.public_id} href={`/portal/requests/${t.public_id}`} className="request-row">
                <div style={{ display: "flex", justifyContent: "space-between", gap: "1rem" }}>
                  <strong>{t.tt_number}</strong>
                  <StatusPill status={t.status} />
                </div>
                <span className="muted">
                  {t.service?.name || "Service"} · {new Date(t.created_at).toLocaleDateString()}
                </span>
              </Link>
            ))
          )}
          {tickets.length > 0 && (
            <div style={{ marginTop: "1rem" }}>
              <Link href="/portal/requests" className="linkish">
                View all requests
              </Link>
            </div>
          )}
        </section>

        <aside className="panel">
          <h2>{me?.company_name || "Your company"}</h2>
          <dl className="muted" style={{ margin: 0, display: "grid", gap: "0.65rem" }}>
            <div>
              <strong style={{ color: "var(--et-ink)" }}>Contact</strong>
              <div>{me?.name}</div>
              <div>{me?.phone_number || "—"}</div>
            </div>
            <div>
              <strong style={{ color: "var(--et-ink)" }}>Organisation</strong>
              {me?.company_tin && <div>TIN: {me.company_tin}</div>}
              {me?.company_phone && <div>{me.company_phone}</div>}
              {me?.company_email && <div>{me.company_email}</div>}
              {me?.company_address && <div>{me.company_address}</div>}
            </div>
          </dl>
          <div style={{ marginTop: "1.5rem", display: "flex", flexWrap: "wrap", gap: "0.75rem" }}>
            <Link href="/portal/services" className="btn-ghost">
              Browse services
            </Link>
            <Link href="/portal/company" className="linkish" style={{ alignSelf: "center" }}>
              Update company profile
            </Link>
          </div>
        </aside>
      </div>
    </>
  );
}
