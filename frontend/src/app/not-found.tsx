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
    <main className="section" style={{ textAlign: "center", paddingTop: "4rem" }}>
      <p className="brand-kicker">404</p>
      <h1>Page not found</h1>
      <p className="muted" style={{ margin: "0.75rem auto 1.5rem", maxWidth: "28rem" }}>
        This link may be outdated, or the page was moved. Head back to the VAS Partners home page.
      </p>
      <Link href="/" className="btn-primary">
        Back to home
      </Link>
    </main>
  );
}
