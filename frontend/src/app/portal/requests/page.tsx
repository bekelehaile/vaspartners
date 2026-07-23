"use client";

import Link from "next/link";
import { StatusPill } from "@/components/StatusJourney";
import { useTickets } from "@/hooks/use-customer";
import { statusCopy } from "@/lib/api";

export default function RequestsPage() {
  const { data: tickets = [], error, isError } = useTickets();

  return (
    <>
      <div
        className="portal-hero"
        style={{ display: "flex", justifyContent: "space-between", gap: "1rem", flexWrap: "wrap" }}
      >
        <div>
          <h1>My requests</h1>
          <p className="muted">Every submission, with a plain-language status.</p>
        </div>
        <Link href="/portal/requests/new" className="btn-primary" style={{ alignSelf: "center" }}>
          New request
        </Link>
      </div>
      <div className="section" style={{ paddingTop: 0 }}>
        {isError && (
          <div className="alert">
            {error instanceof Error ? error.message : "Unable to load requests"}
          </div>
        )}
        <div className="panel">
          {!tickets.length ? (
            <div className="empty">
              No requests yet. Start one when you are ready — it only takes a few minutes.
            </div>
          ) : (
            tickets.map((t) => (
              <Link key={t.public_id} href={`/portal/requests/${t.public_id}`} className="request-row">
                <div
                  style={{
                    display: "flex",
                    justifyContent: "space-between",
                    gap: "1rem",
                    flexWrap: "wrap",
                  }}
                >
                  <strong>{t.tt_number}</strong>
                  <StatusPill status={t.status} />
                </div>
                <span className="muted">{t.service?.name}</span>
                <span className="muted" style={{ fontSize: "0.9rem" }}>
                  {statusCopy[t.status]?.hint}
                </span>
              </Link>
            ))
          )}
        </div>
      </div>
    </>
  );
}
