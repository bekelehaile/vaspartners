"use client";

import Link from "next/link";
import { FormEvent, useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { SiteShell } from "@/components/SiteShell";
import { StatusPill } from "@/components/StatusJourney";
import {
  Client,
  Ticket,
  api,
  clearToken,
  getToken,
} from "@/lib/api";

export default function PortalHomePage() {
  const router = useRouter();
  const [me, setMe] = useState<Client | null>(null);
  const [tickets, setTickets] = useState<Ticket[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [company, setCompany] = useState("");
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (!getToken()) {
      router.replace("/");
      return;
    }
    (async () => {
      try {
        const meRes = await api<{ data: Client }>("/auth/me");
        setMe(meRes.data);
        setCompany(meRes.data.company_name || "");
        const tRes = await api<{ data: Ticket[] }>("/tickets");
        setTickets(Array.isArray(tRes.data) ? tRes.data : []);
      } catch (e) {
        setError(e instanceof Error ? e.message : "Unable to load portal");
      }
    })();
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

  const saveProfile = async (e: FormEvent) => {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      const res = await api<{ data: Client }>("/profile", {
        method: "POST",
        body: JSON.stringify({ company_name: company }),
      });
      setMe(res.data);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Could not save profile");
    } finally {
      setSaving(false);
    }
  };

  const needsProfile = me && !me.profile_completed_at;

  return (
    <SiteShell me={me} onLogout={logout} compact>
      <div className="portal-hero">
        <p className="brand-kicker">Partner portal</p>
        <h1>Hello{me?.name ? `, ${me.name.split(" ")[0]}` : ""}</h1>
        <p className="muted">
          Everything for your VAS requests lives here — start a new one, or check progress on what is
          already moving.
        </p>
      </div>

      {error && (
        <div className="section" style={{ paddingTop: 0 }}>
          <div className="alert">{error}</div>
        </div>
      )}

      {needsProfile && (
        <div className="section" style={{ paddingTop: 0, paddingBottom: "1rem" }}>
          <form className="panel" onSubmit={saveProfile}>
            <h2>One quick detail</h2>
            <p className="muted" style={{ marginTop: "-0.5rem", marginBottom: "1rem" }}>
              Add your company name so our team can recognise your requests at a glance.
            </p>
            <div className="field">
              <label htmlFor="company">Company / organisation</label>
              <input
                id="company"
                value={company}
                onChange={(e) => setCompany(e.target.value)}
                placeholder="e.g. Sunrise Media PLC"
                required
              />
            </div>
            <button className="btn-primary" disabled={saving}>
              {saving ? "Saving…" : "Save and continue"}
            </button>
          </form>
        </div>
      )}

      <div className="portal-grid">
        <section className="panel">
          <div style={{ display: "flex", justifyContent: "space-between", gap: "1rem", alignItems: "center" }}>
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
          <h2>What happens next</h2>
          <ol style={{ margin: 0, paddingLeft: "1.1rem", color: "var(--et-muted)", display: "grid", gap: "0.75rem" }}>
            <li>A supervisor assigns an account manager.</li>
            <li>Documents are checked carefully.</li>
            <li>Approvals move up the chain until final sign-off.</li>
            <li>You will see each change reflected on the request page.</li>
          </ol>
          <div style={{ marginTop: "1.5rem" }}>
            <Link href="/portal/services" className="btn-ghost">
              Browse services
            </Link>
          </div>
        </aside>
      </div>
    </SiteShell>
  );
}
