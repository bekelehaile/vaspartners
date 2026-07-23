"use client";

import Link from "next/link";
import { FormEvent, useEffect, useState } from "react";
import { useParams, useRouter } from "next/navigation";
import { SiteShell } from "@/components/SiteShell";
import { StatusJourney, StatusPill } from "@/components/StatusJourney";
import { Customer, Ticket, api, clearToken, getToken, statusCopy } from "@/lib/api";

export default function RequestDetailPage() {
  const router = useRouter();
  const params = useParams<{ public_id: string }>();
  const [me, setMe] = useState<Customer | null>(null);
  const [ticket, setTicket] = useState<Ticket | null>(null);
  const [comment, setComment] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const load = async () => {
    const meRes = await api<{ data: Customer }>("/auth/me");
    setMe(meRes.data);
    const tRes = await api<{ data: Ticket }>(`/tickets/${params.public_id}`);
    setTicket(tRes.data);
  };

  useEffect(() => {
    if (!getToken()) {
      router.replace("/");
      return;
    }
    load().catch((e) => {
      setError(e instanceof Error ? e.message : "Unable to load request");
    });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [params.public_id, router]);

  const logout = async () => {
    try {
      await api("/auth/logout", { method: "POST" });
    } catch {
      /* ignore */
    }
    clearToken();
    router.replace("/");
  };

  const sendComment = async (e: FormEvent) => {
    e.preventDefault();
    if (!ticket || !comment.trim()) return;
    setBusy(true);
    setError(null);
    try {
      await api(`/tickets/${ticket.public_id}/comments`, {
        method: "POST",
        body: JSON.stringify({ body: comment.trim() }),
      });
      setComment("");
      await load();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Could not post comment");
    } finally {
      setBusy(false);
    }
  };

  return (
    <SiteShell me={me} onLogout={logout} compact>
      <div className="portal-hero">
        <p className="brand-kicker">
          <Link href="/portal/requests" className="linkish">
            ← My requests
          </Link>
        </p>
        <h1>{ticket?.tt_number || "Request"}</h1>
        <p className="muted">
          {ticket?.service?.name || "Service"}
          {ticket?.requisition?.name ? ` · ${ticket.requisition.name}` : ""}
        </p>
        {ticket && <StatusPill status={ticket.status} />}
      </div>

      <div className="section" style={{ paddingTop: 0 }}>
        {error && <div className="alert">{error}</div>}

        {!ticket ? (
          <div className="panel">
            <div className="empty">Loading request…</div>
          </div>
        ) : (
          <div className="portal-grid">
            <section className="panel">
              <h2>Progress</h2>
              <StatusJourney status={ticket.status} />
              <p className="muted" style={{ marginTop: "1rem" }}>
                {statusCopy[ticket.status]?.hint}
              </p>
              {ticket.description && (
                <>
                  <h2 style={{ marginTop: "1.5rem" }}>Description</h2>
                  <p className="muted">{ticket.description}</p>
                </>
              )}

              <h2 style={{ marginTop: "1.5rem" }}>Documents</h2>
              {!ticket.documents?.length ? (
                <div className="empty">No documents uploaded yet.</div>
              ) : (
                <ul style={{ margin: 0, paddingLeft: "1.1rem" }}>
                  {ticket.documents.map((d) => (
                    <li key={d.id}>
                      {d.document_type?.name || "Document"} — {d.original_name}
                    </li>
                  ))}
                </ul>
              )}
            </section>

            <aside className="panel">
              <h2>Activity</h2>
              <ol style={{ margin: 0, paddingLeft: "1.1rem", display: "grid", gap: "0.65rem" }}>
                {(ticket.status_histories || []).map((h, i) => (
                  <li key={`${h.created_at}-${i}`} className="muted">
                    <strong style={{ color: "var(--et-ink)" }}>{h.to_status}</strong>
                    {h.note ? ` — ${h.note}` : ""}
                    <div style={{ fontSize: "0.85rem" }}>
                      {new Date(h.created_at).toLocaleString()}
                    </div>
                  </li>
                ))}
              </ol>

              <form onSubmit={sendComment} style={{ marginTop: "1.25rem" }}>
                <div className="field">
                  <label htmlFor="comment">Add a comment</label>
                  <textarea
                    id="comment"
                    rows={3}
                    value={comment}
                    onChange={(e) => setComment(e.target.value)}
                    required
                  />
                </div>
                <button className="btn-primary" disabled={busy}>
                  {busy ? "Sending…" : "Send"}
                </button>
              </form>
            </aside>
          </div>
        )}
      </div>
    </SiteShell>
  );
}
