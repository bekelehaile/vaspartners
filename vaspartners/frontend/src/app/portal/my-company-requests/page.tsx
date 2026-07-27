"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";

/** Legacy path — company requests live at /portal/company-requests. */
export default function MyCompanyRequestsRedirectPage() {
  const router = useRouter();

  useEffect(() => {
    router.replace("/portal/company-requests");
  }, [router]);

  return (
    <div className="section">
      <p className="muted">Opening company requests…</p>
    </div>
  );
}
