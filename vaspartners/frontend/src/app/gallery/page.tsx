"use client";

import { SiteShell } from "@/components/SiteShell";
import { LandingGallerySection } from "@/components/LandingGallerySection";
import { useContact, useLogout } from "@/hooks/use-contact";

export default function GalleryPage() {
  const { data: me = null } = useContact();
  const logout = useLogout();

  return (
    <SiteShell me={me} onLogout={() => void logout()}>
      <div className="portal-hero">
        <p className="brand-kicker">Gallery</p>
        <h1>Photo gallery</h1>
        <p className="muted">Images managed from the Ethio telecom admin website tools.</p>
      </div>
      <LandingGallerySection showIntro={false} page />
    </SiteShell>
  );
}
