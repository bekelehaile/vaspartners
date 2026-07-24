"use client";

import { SiteShell } from "@/components/SiteShell";
import { LandingGallerySection } from "@/components/LandingGallerySection";
import { useCustomer, useLogout } from "@/hooks/use-customer";

export default function GalleryPage() {
  const { data: me = null } = useCustomer();
  const logout = useLogout();

  return (
    <SiteShell me={me} onLogout={() => void logout()}>
      <div className="portal-hero">
        <p className="brand-kicker">Gallery</p>
        <h1>Photo gallery</h1>
        <p className="muted">Images managed from the Ethio telecom admin website tools.</p>
      </div>
      <LandingGallerySection showIntro={false} />
    </SiteShell>
  );
}
