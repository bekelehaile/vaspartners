"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { useSearchParams } from "next/navigation";
import { AuthWait } from "@/components/AuthWait";

/**
 * Fayda registered redirect lands here (directly on :8443, or via a host :443 hop).
 * PKCE + private-key token exchange stay on Laravel — this page only forwards code/state.
 */
export default function FaydaRedirectBridge() {
  const params = useSearchParams();
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const api = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000/api/v1";
    // Prefer the real browser query string — avoids an empty first useSearchParams paint
    // that was forwarding to the API without code/state → invalid_state.
    const fromWindow = typeof window !== "undefined" ? window.location.search.replace(/^\?/, "") : "";
    const qs = fromWindow || params.toString();
    const sp = new URLSearchParams(qs);
    const code = sp.get("code");
    const state = sp.get("state");

    if (!code || !state) {
      setError("Fayda returned without an authorization code. Please try signing in again.");
      return;
    }

    window.location.replace(`${api}/auth/fayda/callback?${sp.toString()}`);
  }, [params]);

  if (error) {
    return (
      <AuthWait title="Sign-in could not continue">
        <p className="muted">{error}</p>
        <p className="muted">
          <Link href="/">Back to home</Link>
        </p>
      </AuthWait>
    );
  }

  return (
    <AuthWait title="Signing in with Fayda">
      <p className="muted">Handing off to the API to finish secure login…</p>
    </AuthWait>
  );
}
