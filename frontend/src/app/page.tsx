"use client";

import Link from "next/link";
import { Suspense } from "react";
import { useSearchParams } from "next/navigation";
import { SiteShell } from "@/components/SiteShell";
import { LandingBlogSection } from "@/components/LandingBlogSection";
import { LandingGallerySection } from "@/components/LandingGallerySection";
import { LandingServicesSection } from "@/components/LandingServicesSection";
import { faydaLoginUrl } from "@/lib/api";
import { useCustomer, useFaqs, useLogout } from "@/hooks/use-customer";

function LandingInner() {
  const params = useSearchParams();
  const authError = params.get("error");
  const { data: me = null } = useCustomer();
  const { data: faqs = [] } = useFaqs();
  const logout = useLogout();

  return (
    <SiteShell me={me} onLogout={() => void logout()} landing>
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

      <LandingServicesSection />

      <LandingBlogSection />

      <LandingGallerySection />

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

export default function LandingPage() {
  return (
    <Suspense
      fallback={
        <main className="auth-wait">
          <div className="spinner" aria-hidden />
        </main>
      }
    >
      <LandingInner />
    </Suspense>
  );
}
