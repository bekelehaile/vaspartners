"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { SiteShell } from "@/components/SiteShell";
import { StatusPill } from "@/components/StatusJourney";
import { Customer, Ticket, api, clearToken, getToken, statusCopy } from "@/lib/api";

export default function RequestsPage() {
  const router = useRouter();
  const [me, setMe] = useState<Customer | null>(null);
  const [tickets, setTickets] = useState<Ticket[]>([]);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!getToken()) {
      router.replace("/");
      return;
    }
    api<{ data: Customer }>("/auth/me").then((r) => setMe(r.data)).catch(() => router.replace("/"));
    api<{ data: Ticket[] }>("/tickets")
      .then((r) => setTickets(Array.isArray(r.data) ? r.data : []))
      .catch((e) => setError(e.message));
  }, [router]);

  const logout = async () => {
    try {
      await api("/auth/logout", { method: "POST" });
    } catch {
      /* ignore */
    }
    clearToken();
    router.replace("/");
  };

  return (
    <SiteShell me={me} onLogout={logout} compact>
      <div className="portal-hero" style={{ display: "flex", justifyContent: "space-between", gap: "1rem", flexWrap: "wrap" }}>
        <div>
          <h1>My requests</h1>
          <p className="muted">Every submission, with a plain-language status.</p>
        </div>
        <Link href="/portal/requests/new" className="btn-primary" style={{ alignSelf: "center" }}>
          New request
        </Link>
      </div>
      <div className="section" style={{ paddingTop: 0 }}>
        {error && <div className="alert">{error}</div>}
        <div className="panel">
          {!tickets.length ? (
            <div className="empty">No requests yet. Start one when you are ready — it only takes a few minutes.</div>
          ) : (
            tickets.map((t) => (
              <Link key={t.public_id} href={`/portal/requests/${t.public_id}`} className="request-row">
                <div style={{ display: "flex", justifyContent: "space-between", gap: "1rem", flexWrap: "wrap" }}>
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
    </SiteShell>
  );
}
