"use client";

import Link from "next/link";
import { Suspense } from "react";
import { useSearchParams } from "next/navigation";
import { SiteShell } from "@/components/SiteShell";
import { LandingBlogSection } from "@/components/LandingBlogSection";
import { LandingFaqSection } from "@/components/FaqList";
import { LandingGallerySection } from "@/components/LandingGallerySection";
import { LandingServicesSection } from "@/components/LandingServicesSection";
import { faydaLoginUrl } from "@/lib/api";
import { useCustomer, useLogout } from "@/hooks/use-customer";

function LandingInner() {
  const params = useSearchParams();
  const authError = params.get("error");
  const { data: me = null } = useCustomer();
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
                <Link
                  className="btn-hero"
                  href={me.profile_completed ? "/portal" : "/portal/company"}
                >
                  {me.profile_completed
                    ? "Go to my portal →"
                    : "Complete company setup →"}
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

          <div className="hero-visual">
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img
              src="/brand/services.svg"
              alt="Value Added Services for Ethio telecom partners"
              width={560}
              height={420}
            />
          </div>
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

      <LandingFaqSection />
    </SiteShell>
  );
}

export function LandingPage() {
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
