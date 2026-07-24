import type { Metadata } from "next";
import { Suspense } from "react";
import FaydaRedirectBridge from "./callback-inner";
import { buildPageMetadata } from "@/lib/seo";

export const metadata: Metadata = buildPageMetadata({
  title: "Signing in",
  description: "Completing Fayda secure sign-in.",
  path: "/callback",
  noIndex: true,
});

export default function FaydaCallbackPage() {
  return (
    <Suspense
      fallback={
        <main className="auth-wait">
          <div className="spinner" aria-hidden />
        </main>
      }
    >
      <FaydaRedirectBridge />
    </Suspense>
  );
}
