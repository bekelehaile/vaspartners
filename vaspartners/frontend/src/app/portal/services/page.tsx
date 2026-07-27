"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";

/** Services catalog lives on the public home page — portal link is redundant. */
export default function PortalServicesRedirectPage() {
  const router = useRouter();

  useEffect(() => {
    router.replace("/#services");
  }, [router]);

  return (
    <div className="section">
      <p className="muted">Opening services…</p>
    </div>
  );
}
