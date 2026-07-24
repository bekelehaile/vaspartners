import type { Metadata } from "next";
import { Suspense } from "react";
import { AuthWait } from "@/components/AuthWait";
import AuthCallbackInner from "./callback-inner";
import { buildPageMetadata } from "@/lib/seo";

export const metadata: Metadata = buildPageMetadata({
  title: "Signing in",
  description: "Completing secure sign-in.",
  path: "/auth/callback",
  noIndex: true,
});

export default function AuthCallbackPage() {
  return (
    <Suspense fallback={<AuthWait />}>
      <AuthCallbackInner />
    </Suspense>
  );
}
