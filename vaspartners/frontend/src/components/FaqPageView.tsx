"use client";

import { SiteShell } from "@/components/SiteShell";
import { FaqList } from "@/components/FaqList";
import { useCustomer, useLogout } from "@/hooks/use-customer";

export function FaqPageView() {
  const { data: me = null } = useCustomer();
  const logout = useLogout();

  return (
    <SiteShell me={me} onLogout={() => void logout()}>
      <div className="portal-hero">
        <p className="brand-kicker">FAQ</p>
        <h1>Frequently asked questions</h1>
        <p className="muted">
          Answers for VAS partners, managed from the Ethio telecom admin website tools.
        </p>
      </div>

      <div className="section">
        <FaqList />
      </div>
    </SiteShell>
  );
}
