"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";

/** Legacy path — My service requests lives on /portal. */
export default function RequestsRedirectPage() {
  const router = useRouter();

  useEffect(() => {
    router.replace("/portal");
  }, [router]);

  return null;
}
