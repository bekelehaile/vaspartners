"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { SiteShell } from "@/components/SiteShell";
import { Customer, api, clearToken, faydaLoginUrl, getToken } from "@/lib/api";

export default function LandingPage() {
  const [me, setMe] = useState<Customer | null>(null);

  useEffect(() => {
    if (!getToken()) return;
    api<{ data: Customer }>("/auth/me")
      .then((r) => setMe(r.data))
      .catch(() => clearToken());
  }, []);

  const logout = async () => {
    try {
      await api("/auth/logout", { method: "POST" });
    } catch {
      /* ignore */
    }
    clearToken();
    setMe(null);
  };

  return (
    <SiteShell me={me} onLogout={logout}>
      <section className="hero" aria-label="Welcome">
        <div className="hero-media" aria-hidden />
        <div className="hero-copy">
          <h1 className="hero-brand">VAS Partners</h1>
          <p className="hero-lead">
            Request Value Added Services in minutes — upload once, track every step, stay informed.
          </p>
          <div className="hero-actions">
            {me ? (
              <Link className="btn-on-dark" href="/portal">
                Go to my portal
              </Link>
            ) : (
              <a className="btn-on-dark" href={faydaLoginUrl()}>
                Continue with Fayda
              </a>
            )}
            <a className="btn-on-dark-ghost" href="#how-it-works">
              How it works
            </a>
          </div>
        </div>
      </section>

      <section className="section" id="how-it-works">
        <h2>A calm path from request to approval</h2>
        <p className="section-lead">
          Built for partners who want clarity — not paperwork theatre. Three simple moves, always
          visible status.
        </p>
        <div className="quiet-steps">
          <article className="quiet-step">
            <h3>Sign in with Fayda</h3>
            <p>No signup form — Fayda gives us your verified identity and we open your portal.</p>
          </article>
          <article className="quiet-step">
            <h3>Tell us what you need</h3>
            <p>Pick a service, attach the required documents, and submit. We guide every field.</p>
          </article>
          <article className="quiet-step">
            <h3>Watch it move</h3>
            <p>Assignment, document check, and approvals update live so you always know where you stand.</p>
          </article>
        </div>
      </section>
    </SiteShell>
  );
}
