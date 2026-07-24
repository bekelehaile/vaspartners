import type { Metadata } from "next";
import Link from "next/link";
import { buildPageMetadata } from "@/lib/seo";

export const metadata: Metadata = buildPageMetadata({
  title: "Page not found",
  description: "The page you requested could not be found on the VAS Partners site.",
  path: "/404",
  noIndex: true,
});

export default function NotFound() {
  return (
    <main className="auth-wait">
      <div className="auth-wait-card">
        {/* eslint-disable-next-line @next/next/no-img-element */}
        <img
          src="/brand/ethio_logo_full.png"
          alt="Ethio telecom"
          className="auth-wait-logo"
        />
        <p className="brand-kicker">404</p>
        <h1 className="auth-wait-title">Page not found</h1>
        <p className="muted" style={{ margin: "0.35rem 0 1.25rem" }}>
          This link may be outdated, or the page was moved.
        </p>
        <Link href="/" className="btn-primary">
          Back to home
        </Link>
      </div>
    </main>
  );
}
