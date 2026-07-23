import { PortalGuard } from "@/components/PortalGuard";

export default function PortalLayout({ children }: { children: React.ReactNode }) {
  return <PortalGuard>{children}</PortalGuard>;
}
