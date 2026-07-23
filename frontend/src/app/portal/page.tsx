"use client";

import Link from "next/link";
import { FormEvent, useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { SiteShell } from "@/components/SiteShell";
import { StatusPill } from "@/components/StatusJourney";
import {
  Customer,
  Ticket,
  api,
  clearToken,
  getToken,
} from "@/lib/api";

export default function PortalHomePage() {
  const router = useRouter();
  const [me, setMe] = useState<Customer | null>(null);
  const [tickets, setTickets] = useState<Ticket[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [company, setCompany] = useState({
    company_name: "",
    company_tin: "",
    company_phone: "",
    company_email: "",
    company_address: "",
  });

  useEffect(() => {
    if (!getToken()) {
      router.replace("/");
      return;
    }
    (async () => {
      try {
        const meRes = await api<{ data: Customer }>("/auth/me");
        setMe(meRes.data);
        setCompany({
          company_name: meRes.data.company_name || "",
          company_tin: meRes.data.company_tin || "",
          company_phone: meRes.data.company_phone || "",
          company_email: meRes.data.company_email || "",
          company_address: meRes.data.company_address || "",
        });
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

  const saveCompany = async (e: FormEvent) => {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      const res = await api<{ data: Customer }>("/profile/company", {
        method: "POST",
        body: JSON.stringify(company),
      });
      setMe(res.data);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Could not save company details");
    } finally {
      setSaving(false);
    }
  };

  const needsCompany = me && !me.profile_completed;

  return (
    <SiteShell me={me} onLogout={logout} compact>
      <div className="portal-hero">
        <p className="brand-kicker">Partner portal</p>
        <h1>Hello{me?.name ? `, ${me.name.split(" ")[0]}` : ""}</h1>
        <p className="muted">
          Signed in with Fayda. Complete your company profile once, then manage VAS requests here.
        </p>
      </div>

      {error && (
        <div className="section" style={{ paddingTop: 0 }}>
          <div className="alert">{error}</div>
        </div>
      )}

      {needsCompany && (
        <div className="section" style={{ paddingTop: 0, paddingBottom: "1rem" }}>
          <form className="panel" onSubmit={saveCompany}>
            <h2>Company details</h2>
            <p className="muted" style={{ marginTop: "-0.5rem", marginBottom: "1rem" }}>
              Fayda verified your identity. Tell us about the organisation so our team can process
              service requests.
            </p>
            <div className="field">
              <label htmlFor="company_name">Company / organisation name</label>
              <input
                id="company_name"
                value={company.company_name}
                onChange={(e) => setCompany((c) => ({ ...c, company_name: e.target.value }))}
                placeholder="e.g. Sunrise Media PLC"
                required
              />
            </div>
            <div className="field">
              <label htmlFor="company_tin">TIN (optional)</label>
              <input
                id="company_tin"
                value={company.company_tin}
                onChange={(e) => setCompany((c) => ({ ...c, company_tin: e.target.value }))}
              />
            </div>
            <div className="field">
              <label htmlFor="company_phone">Company phone (optional)</label>
              <input
                id="company_phone"
                value={company.company_phone}
                onChange={(e) => setCompany((c) => ({ ...c, company_phone: e.target.value }))}
              />
            </div>
            <div className="field">
              <label htmlFor="company_email">Company email (optional)</label>
              <input
                id="company_email"
                type="email"
                value={company.company_email}
                onChange={(e) => setCompany((c) => ({ ...c, company_email: e.target.value }))}
              />
            </div>
            <div className="field">
              <label htmlFor="company_address">Company address (optional)</label>
              <textarea
                id="company_address"
                value={company.company_address}
                onChange={(e) => setCompany((c) => ({ ...c, company_address: e.target.value }))}
                rows={3}
              />
            </div>
            <button className="btn-primary" disabled={saving}>
              {saving ? "Saving…" : "Save company details"}
            </button>
          </form>
        </div>
      )}

      {!needsCompany && (
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
            <h2>{me?.company_name || "Your company"}</h2>
            <dl className="muted" style={{ margin: 0, display: "grid", gap: "0.45rem" }}>
              <div>
                <strong style={{ color: "var(--et-ink)" }}>Contact</strong>
                <div>{me?.name}</div>
                <div>{me?.phone_number || "—"}</div>
              </div>
              {(me?.company_tin || me?.company_email) && (
                <div>
                  <strong style={{ color: "var(--et-ink)" }}>Company</strong>
                  {me?.company_tin && <div>TIN: {me.company_tin}</div>}
                  {me?.company_email && <div>{me.company_email}</div>}
                </div>
              )}
            </dl>
            <div style={{ marginTop: "1.5rem" }}>
              <Link href="/portal/services" className="btn-ghost">
                Browse services
              </Link>
            </div>
          </aside>
        </div>
      )}
    </SiteShell>
  );
}
