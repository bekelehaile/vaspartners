"use client";

import Link from "next/link";
import { useEffect, useId, useRef, useState } from "react";
import { LogOutIcon, UserRoundIcon } from "lucide-react";
import { Button } from "@/components/ui/button";
import {
  companySectionHref,
  navigatePortalHref,
} from "@/lib/portal-company-nav";

export function PortalSettingsMenu({
  onNavigate,
  onLogout,
}: {
  onNavigate?: () => void;
  onLogout?: () => void;
}) {
  const [open, setOpen] = useState(false);
  const rootRef = useRef<HTMLDivElement>(null);
  const menuId = useId();

  useEffect(() => {
    if (!open) return;

    const onPointerDown = (e: MouseEvent) => {
      if (!rootRef.current?.contains(e.target as Node)) setOpen(false);
    };
    const onKeyDown = (e: KeyboardEvent) => {
      if (e.key === "Escape") setOpen(false);
    };

    document.addEventListener("mousedown", onPointerDown);
    document.addEventListener("keydown", onKeyDown);
    return () => {
      document.removeEventListener("mousedown", onPointerDown);
      document.removeEventListener("keydown", onKeyDown);
    };
  }, [open]);

  const close = () => {
    setOpen(false);
    onNavigate?.();
  };

  const go = (href: string) => {
    close();
    navigatePortalHref(href);
  };

  return (
    <div className="portal-settings" ref={rootRef}>
      <Button
        type="button"
        variant="outline"
        size="sm"
        className="portal-account-btn"
        aria-expanded={open}
        aria-haspopup="menu"
        aria-controls={menuId}
        aria-label="Account"
        onClick={() => setOpen((v) => !v)}
      >
        <UserRoundIcon />
        <span>Account</span>
      </Button>

      {open && (
        <div className="portal-settings-menu" id={menuId} role="menu">
          <p className="portal-settings-menu-label">Account</p>
          <Link
            href={companySectionHref("identity")}
            role="menuitem"
            onClick={(e) => {
              e.preventDefault();
              go(companySectionHref("identity"));
            }}
          >
            Identity
          </Link>
          <Link
            href={companySectionHref("profile")}
            role="menuitem"
            onClick={(e) => {
              e.preventDefault();
              go(companySectionHref("profile"));
            }}
          >
            Company
          </Link>
          <Link
            href={companySectionHref("members")}
            role="menuitem"
            onClick={(e) => {
              e.preventDefault();
              go(companySectionHref("members"));
            }}
          >
            Members
          </Link>
          <div className="portal-settings-divider" role="separator" />
          <p className="portal-settings-menu-label">Requests</p>
          <Link href="/portal/company-requests" role="menuitem" onClick={close}>
            Company requests
          </Link>
          <Link
            href="/portal/membership-requests"
            role="menuitem"
            onClick={close}
          >
            Membership requests
          </Link>
          {onLogout && (
            <>
              <div className="portal-settings-divider" role="separator" />
              <button
                type="button"
                role="menuitem"
                className="portal-settings-signout"
                onClick={() => {
                  close();
                  onLogout();
                }}
              >
                <LogOutIcon className="size-3.5 opacity-70" aria-hidden />
                Sign out
              </button>
            </>
          )}
        </div>
      )}
    </div>
  );
}
