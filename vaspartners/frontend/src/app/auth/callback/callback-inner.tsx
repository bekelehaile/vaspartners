"use client";

import { useEffect } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { useQueryClient } from "@tanstack/react-query";
import { AuthWait } from "@/components/AuthWait";
import { api, setToken } from "@/lib/api";
import type { Customer } from "@/lib/api";
import { queryKeys } from "@/lib/query-keys";

export default function AuthCallbackInner() {
  const params = useSearchParams();
  const router = useRouter();
  const queryClient = useQueryClient();

  useEffect(() => {
    const token = params.get("token");
    const error = params.get("error");

    if (!token) {
      router.replace(`/?error=${encodeURIComponent(error || "auth_failed")}`);
      return;
    }

    setToken(token);

    let cancelled = false;
    (async () => {
      try {
        const res = await api<{ data: Customer }>("/auth/me");
        if (cancelled) return;
        queryClient.setQueryData(queryKeys.customer.me, res.data);
        const next = res.data.profile_completed ? "/portal" : "/portal/company";
        router.replace(next);
      } catch {
        if (!cancelled) router.replace("/portal/company");
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [params, router, queryClient]);

  return (
    <AuthWait title="Welcome aboard">
      <p className="muted">Confirming your Fayda identity and opening your portal…</p>
    </AuthWait>
  );
}
