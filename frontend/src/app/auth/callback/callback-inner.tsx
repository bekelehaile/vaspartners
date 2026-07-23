"use client";

import { useEffect } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { setToken } from "@/lib/api";

export default function AuthCallbackInner() {
  const params = useSearchParams();
  const router = useRouter();

  useEffect(() => {
    const token = params.get("token");
    const error = params.get("error");

    if (token) {
      setToken(token);
      const t = window.setTimeout(() => router.replace("/portal"), 700);
      return () => window.clearTimeout(t);
    }

    router.replace(`/?error=${encodeURIComponent(error || "auth_failed")}`);
  }, [params, router]);

  return (
    <main className="auth-wait">
      <div>
        <div className="spinner" aria-hidden />
        <h1 className="font-serif" style={{ fontFamily: "var(--font-display), Georgia, serif", margin: "0 0 0.4rem" }}>
          Welcome aboard
        </h1>
        <p className="muted">Confirming your Fayda identity and opening your portal…</p>
      </div>
    </main>
  );
}
