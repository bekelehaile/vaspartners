"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { SiteShell } from "@/components/SiteShell";
import { Customer, api, clearToken, faydaLoginUrl, getToken } from "@/lib/api";

type Faq = { id: number; question: string; answer: string };

export default function LandingPage() {
  const [me, setMe] = useState<Customer | null>(null);
  const [authError, setAuthError] = useState<string | null>(null);
  const [faqs, setFaqs] = useState<Faq[]>([]);

  useEffect(() => {
    const err = new URLSearchParams(window.location.search).get("error");
    if (err) setAuthError(err);

    api<{ data: Faq[] }>("/faqs")
      .then((r) => setFaqs(Array.isArray(r.data) ? r.data.slice(0, 12) : []))
      .catch(() => setFaqs([]));

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
    <SiteShell me={me} onLogout={logout} landing>
      <div className="hero-wrap">
        <section className="hero" aria-label="Welcome">
          <div className="hero-copy">
            <h1 className="hero-brand">
              Manage Your <em>VAS Partners</em> Online
            </h1>
            <p className="hero-lead">
              Request Value Added Services, upload documents, and track every approval step — the
              Ethio telecom way.
            </p>
            {authError && (
              <p className="alert" style={{ marginBottom: "1rem", maxWidth: "28rem" }}>
                Fayda sign-in failed ({authError}). Try again, or check API logs if it keeps failing.
              </p>
            )}
            <div className="hero-actions">
              {me ? (
                <Link className="btn-hero" href="/portal">
                  Go to my portal →
                </Link>
              ) : (
                <a className="btn-hero" href={faydaLoginUrl()}>
                  Get started →
                </a>
              )}
              <a className="btn-hero-ghost" href="#services">
                Explore services
              </a>
            </div>
          </div>

          <aside className="hero-panel" aria-label="Partner portal highlights">
            <h2>Partner portal</h2>
            <p>
              Sign in with Fayda (National ID), complete your company profile once, then submit and
              track VAS requests across categories and service types.
            </p>
            <div className="hero-panel-stat">
              <span>100% Online</span>
              <span>Document guided</span>
              <span>Official platform</span>
            </div>
          </aside>
        </section>
      </div>

      <section className="section" id="services">
        <span className="section-label">Our services</span>
        <h2>Comprehensive VAS solutions</h2>
        <p className="section-lead">
          Categories and service types from the Ethio telecom VAS partners catalog — request online
          and follow progress in real time.
        </p>
        <div className="service-grid">
          <article className="service-tile">
            <div className="service-tile-icon" aria-hidden>
              C
            </div>
            <h3>Service categories</h3>
            <p>
              VAS sales, FinTech, Enterprise Solution, Marketing, Startup Partner, and more partner
              groups.
            </p>
          </article>
          <article className="service-tile">
            <div className="service-tile-icon" aria-hidden>
              T
            </div>
            <h3>Service types</h3>
            <p>
              Digital and VAS offerings such as MT, A2P, USSD, CRBT, Collocation, VISP, and merchant
              services.
            </p>
          </article>
          <article className="service-tile">
            <div className="service-tile-icon" aria-hidden>
              R
            </div>
            <h3>Request types</h3>
            <p>
              New request, maintenance, move, termination, whitelist, tariff change, and other
              requisitions.
            </p>
          </article>
        </div>
      </section>

      <div className="feature-band">
        <section className="section" id="how-it-works">
          <span className="section-label">How it works</span>
          <h2>From Fayda sign-in to approval</h2>
          <p className="section-lead">
            Same Ethio telecom customer experience as fixed services — secure identity, clear steps,
            official processing.
          </p>
          <div className="quiet-steps">
            <article className="quiet-step">
              <h3>Sign in with Fayda</h3>
              <p>Use your National ID — Fayda verifies you and opens your partner portal.</p>
            </article>
            <article className="quiet-step">
              <h3>Choose category &amp; service</h3>
              <p>Pick the VAS service and requisition type, then attach the required documents.</p>
            </article>
            <article className="quiet-step">
              <h3>Track your ticket</h3>
              <p>Follow assignment, document review, and approvals without chasing emails.</p>
            </article>
          </div>
        </section>
      </div>

      <section className="section" id="faq">
        <span className="section-label">FAQ</span>
        <h2>Frequently asked questions</h2>
        <p className="section-lead">Common VAS partner questions from the Ethio telecom catalog.</p>
        <div className="faq-list">
          {faqs.map((f) => (
            <details key={f.id} className="faq-item">
              <summary>{f.question}</summary>
              <p>{f.answer}</p>
            </details>
          ))}
          {!faqs.length && (
            <p className="muted">FAQ content will appear here after the catalog is seeded.</p>
          )}
        </div>
      </section>
    </SiteShell>
  );
}
