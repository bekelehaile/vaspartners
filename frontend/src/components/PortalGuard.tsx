"use client";

import { ReactNode, useEffect } from "react";
import { usePathname, useRouter } from "next/navigation";
import { SiteShell } from "@/components/SiteShell";
import { getToken } from "@/lib/api";
import { useCustomer, useLogout } from "@/hooks/use-customer";

/**
 * Auth + approved-company gate for all /portal routes.
 * Partners cannot use services until their company TIN is admin-approved.
 * Incomplete / pending profiles are forced to /portal/company.
 */
export function PortalGuard({ children }: { children: ReactNode }) {
  const router = useRouter();
  const pathname = usePathname();
  const logout = useLogout();
  const { data: me, isLoading, isError, error } = useCustomer();

  const onCompanyPage = pathname === "/portal/company";
  const canUseServices = !!me?.profile_completed;

  useEffect(() => {
    if (!getToken()) {
      router.replace("/");
      return;
    }
    if (isLoading) return;
    if (isError) {
      router.replace("/");
      return;
    }
    if (me && !canUseServices && !onCompanyPage) {
      router.replace("/portal/company");
    }
  }, [me, isLoading, isError, canUseServices, onCompanyPage, router]);

  if (!getToken() || isLoading || !me) {
    return (
      <main className="auth-wait">
        <div>
          <div className="spinner" aria-hidden />
          <h1 style={{ margin: "0 0 0.4rem" }}>Opening portal</h1>
          <p className="muted">
            {isError
              ? error instanceof Error
                ? error.message
                : "Session expired"
              : "Checking your Fayda session…"}
          </p>
        </div>
      </main>
    );
  }

  if (!canUseServices && !onCompanyPage) {
    return (
      <main className="auth-wait">
        <div>
          <div className="spinner" aria-hidden />
          <p className="muted">
            Approved company required — redirecting…
          </p>
        </div>
      </main>
    );
  }

  return (
    <SiteShell me={me} onLogout={() => void logout()} compact>
      {children}
    </SiteShell>
  );
}
