"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { usePathname } from "next/navigation";
import {
  Building2Icon,
  ClipboardListIcon,
  FileTextIcon,
  MessageSquareIcon,
  PackageIcon,
  UserPlusIcon,
  UserRoundIcon,
  UsersIcon,
} from "lucide-react";
import {
  companySectionFromHash,
  companySectionHref,
  navigatePortalHref,
  type CompanySectionId,
} from "@/lib/portal-company-nav";
import { cn } from "@/lib/utils";

type NavItem = {
  href: string;
  label: string;
  icon: typeof ClipboardListIcon;
  match: (path: string, hash: string) => boolean;
  onNavigate?: (href: string) => void;
};

const workspaceNav: NavItem[] = [
  {
    href: "/portal",
    label: "Service requests",
    icon: ClipboardListIcon,
    match: (path) => path === "/portal" || path.startsWith("/portal/requests"),
  },
  {
    href: "/portal/subscriptions",
    label: "Subscriptions",
    icon: PackageIcon,
    match: (path) => path.startsWith("/portal/subscriptions"),
  },
  {
    href: "/portal/feedback",
    label: "Feedback",
    icon: MessageSquareIcon,
    match: (path) => path.startsWith("/portal/feedback"),
  },
];

function companySectionActive(section: CompanySectionId, path: string, hash: string) {
  if (path !== "/portal/company") return false;
  const fromHash = companySectionFromHash(hash);
  // Bare /portal/company defaults to Identity
  if (!fromHash) return section === "identity";
  return fromHash === section;
}

const organisationNav: NavItem[] = [
  {
    href: companySectionHref("identity"),
    label: "Identity",
    icon: UserRoundIcon,
    match: (path, hash) => companySectionActive("identity", path, hash),
  },
  {
    href: companySectionHref("profile"),
    label: "Company",
    icon: Building2Icon,
    match: (path, hash) => companySectionActive("profile", path, hash),
  },
  {
    href: companySectionHref("members"),
    label: "Members",
    icon: UsersIcon,
    match: (path, hash) => companySectionActive("members", path, hash),
  },
  {
    href: "/portal/company-requests",
    label: "Company requests",
    icon: FileTextIcon,
    match: (path) =>
      path.startsWith("/portal/company-requests") ||
      path.startsWith("/portal/my-company-requests"),
  },
  {
    href: "/portal/membership-requests",
    label: "Membership requests",
    icon: UserPlusIcon,
    match: (path) => path.startsWith("/portal/membership-requests"),
  },
];

function useLocationHash() {
  const [hash, setHash] = useState("");

  useEffect(() => {
    const read = () => setHash(window.location.hash.replace(/^#/, ""));
    read();
    window.addEventListener("hashchange", read);
    return () => window.removeEventListener("hashchange", read);
  }, []);

  return hash;
}

function NavGroup({
  title,
  items,
  onNavigate,
}: {
  title: string;
  items: NavItem[];
  onNavigate?: () => void;
}) {
  const pathname = usePathname();
  const hash = useLocationHash();

  return (
    <div className="portal-sidebar-group">
      <p className="portal-sidebar-label">{title}</p>
      <nav className="portal-sidebar-nav" aria-label={title}>
        {items.map((item) => {
          const active = item.match(pathname, hash);
          const Icon = item.icon;
          return (
            <Link
              key={item.href}
              href={item.href}
              className={cn("portal-sidebar-link", active && "is-active")}
              aria-current={active ? "page" : undefined}
              onClick={(e) => {
                if (item.href.includes("#")) {
                  e.preventDefault();
                  navigatePortalHref(item.href);
                }
                onNavigate?.();
              }}
            >
              <Icon className="portal-sidebar-link-icon" aria-hidden />
              <span>{item.label}</span>
            </Link>
          );
        })}
      </nav>
    </div>
  );
}

export function PortalSidebar({ onNavigate }: { onNavigate?: () => void }) {
  return (
    <div className="portal-sidebar">
      <NavGroup title="Workspace" items={workspaceNav} onNavigate={onNavigate} />
      <NavGroup
        title="Organisation"
        items={organisationNav}
        onNavigate={onNavigate}
      />
    </div>
  );
}
