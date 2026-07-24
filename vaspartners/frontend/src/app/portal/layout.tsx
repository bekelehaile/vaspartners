import type { Metadata } from "next";
import { PortalGuard } from "@/components/PortalGuard";
import { buildPageMetadata } from "@/lib/seo";

export const metadata: Metadata = buildPageMetadata({
  title: "Partner portal",
  description: "Secure VAS Partners portal for authenticated Ethio telecom partners.",
  path: "/portal",
  noIndex: true,
});

export default function PortalLayout({ children }: { children: React.ReactNode }) {
  return <PortalGuard>{children}</PortalGuard>;
}
