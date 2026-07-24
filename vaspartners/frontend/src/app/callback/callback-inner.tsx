"use client";

import { useEffect } from "react";
import { useSearchParams } from "next/navigation";
import { AuthWait } from "@/components/AuthWait";

/**
 * Fayda (eSignet) registered redirect for local dev is http://localhost:3000/callback.
 * PKCE + private-key token exchange stay on Laravel — this page only forwards code/state.
 */
export default function FaydaRedirectBridge() {
  const params = useSearchParams();

  useEffect(() => {
    const api = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000/api/v1";
    const qs = params.toString();
    window.location.replace(`${api}/auth/fayda/callback${qs ? `?${qs}` : ""}`);
  }, [params]);

  return (
    <AuthWait title="Signing in with Fayda">
      <p className="muted">Handing off to the API to finish secure login…</p>
    </AuthWait>
  );
}
