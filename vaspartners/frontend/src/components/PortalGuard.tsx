"use client";

import { ReactNode, useEffect } from "react";
import { usePathname, useRouter } from "next/navigation";
import { AuthWait } from "@/components/AuthWait";
import { SiteShell } from "@/components/SiteShell";
import { TinValidationGate } from "@/components/TinValidationGate";
import { ErcaNameConsentGate } from "@/components/ErcaNameConsentGate";
import { getToken } from "@/lib/api";
import { useContact, useLogout } from "@/hooks/use-contact";

/**
 * Auth + TIN number gate for /portal routes.
 * Contacts use Fayda/CRM identity. Companies use ERCA TIN number validation for services.
 */
export function PortalGuard({ children }: { children: ReactNode }) {
  const router = useRouter();
  const pathname = usePathname();
  const logout = useLogout();
  const { data: me, isLoading, isError, error } = useContact();

  const onCompanyPage = pathname === "/portal/company";
  const canUseServices = !!me?.profile_completed;

  useEffect(() => {
    if (!getToken()) {
      router.replace("/login");
      return;
    }
    if (isLoading) return;
    if (isError) {
      router.replace("/login");
      return;
    }
    if (me && !canUseServices && !onCompanyPage) {
      router.replace("/portal/company");
    }
  }, [me, isLoading, isError, canUseServices, onCompanyPage, router]);

  if (!getToken() || isLoading || !me) {
    return (
      <AuthWait title="Opening portal">
        <p className="muted">
          {isError
            ? error instanceof Error
              ? error.message
              : "Session expired"
            : "Checking your session…"}
        </p>
      </AuthWait>
    );
  }

  if (!canUseServices && !onCompanyPage) {
    return (
      <AuthWait>
        <p className="muted">Company setup required — redirecting…</p>
      </AuthWait>
    );
  }

  const body = (
    <ErcaNameConsentGate>
      {onCompanyPage ? children : <TinValidationGate>{children}</TinValidationGate>}
    </ErcaNameConsentGate>
  );

  return (
    <SiteShell me={me} onLogout={() => void logout()} compact>
      {body}
    </SiteShell>
  );
}
