"use client";

import Link from "next/link";
import { Suspense } from "react";
import { useSearchParams } from "next/navigation";
import { AuthWait } from "@/components/AuthWait";
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
        <section className="hero" aria-label="VAS Partners">
          <div className="hero-copy">
            <h1 className="hero-brand">
              Manage Your <em>VAS Services</em> Online
            </h1>
            <p className="hero-lead">
              Request Value Added Services, upload documents, and track every approval step.
            </p>
            {authError && (
              <p className="alert" style={{ marginBottom: "1rem", maxWidth: "28rem" }}>
                Fayda sign-in failed ({authError}). Please try again.
              </p>
            )}
            <div className="hero-actions">
              {me ? (
                <Link
                  className="btn-hero"
                  href={me.profile_completed ? "/portal" : "/portal/company"}
                >
                  {me.profile_completed ? "Go to my portal" : "Complete company setup"}
                </Link>
              ) : (
                <a className="btn-hero" href={faydaLoginUrl()}>
                  Get started
                </a>
              )}
              <a className="btn-hero-ghost" href="#services">
                Explore services
              </a>
            </div>
          </div>

          <div className="hero-visual" aria-hidden="true">
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img
              src="/brand/services.svg"
              alt=""
              width={560}
              height={420}
            />
          </div>
        </section>
      </div>

      <LandingServicesSection />

      <section className="feature-band" id="how-it-works" aria-labelledby="how-heading">
        <div className="section">
          <header className="section-head">
            <span className="section-label">Process</span>
            <h2 id="how-heading">How it works</h2>
            <p className="section-lead">
              A clear path from identity verification to service approval.
            </p>
          </header>
          <ol className="process-steps">
            <li className="process-step">
              <span className="process-step-num" aria-hidden>
                01
              </span>
              <h3>Sign in with Fayda</h3>
              <p>Verify your identity with National ID and open your partner account.</p>
            </li>
            <li className="process-step">
              <span className="process-step-num" aria-hidden>
                02
              </span>
              <h3>Select a service</h3>
              <p>Choose the VAS product, review criteria, and attach required documents.</p>
            </li>
            <li className="process-step">
              <span className="process-step-num" aria-hidden>
                03
              </span>
              <h3>Track approval</h3>
              <p>Follow assignment, document review, and final decision in one place.</p>
            </li>
          </ol>
        </div>
      </section>

      <LandingFaqSection />

      <LandingBlogSection />

      <LandingGallerySection />
    </SiteShell>
  );
}

export function LandingPage() {
  return (
    <Suspense fallback={<AuthWait />}>
      <LandingInner />
    </Suspense>
  );
}
