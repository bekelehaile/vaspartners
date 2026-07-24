import type { Metadata } from "next";
import { Suspense } from "react";
import { AuthWait } from "@/components/AuthWait";
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
    <Suspense fallback={<AuthWait />}>
      <FaydaRedirectBridge />
    </Suspense>
  );
}
