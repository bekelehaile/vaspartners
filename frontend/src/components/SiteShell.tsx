"use client";

import Link from "next/link";
import { ReactNode } from "react";
import { Customer, clearToken, faydaLoginUrl } from "@/lib/api";

export function SiteShell({
  children,
  me,
  onLogout,
  compact = false,
}: {
  children: ReactNode;
  me?: Customer | null;
  onLogout?: () => void;
  compact?: boolean;
}) {
  return (
    <div className="site">
      <header className={`topbar ${compact ? "topbar-compact" : ""}`}>
        <Link href={me ? "/portal" : "/"} className="brand-lockup">
          <span className="brand-kicker">Ethio Telecom</span>
          <span className="brand-name">VAS Partners</span>
        </Link>
        <nav className="topnav">
          {me ? (
            <>
              <Link href="/portal">Home</Link>
              <Link href="/portal/services">Services</Link>
              <Link href="/portal/requests">My requests</Link>
              <Link href="/portal/requests/new" className="nav-cta">
                New request
              </Link>
              <div className="user-chip">
                <span>{me.company_name || me.name}</span>
                <button type="button" onClick={onLogout} className="linkish">
                  Sign out
                </button>
              </div>
            </>
          ) : (
            <a className="nav-cta" href={faydaLoginUrl()}>
              Sign in with Fayda
            </a>
          )}
        </nav>
      </header>
      {children}
      <footer className="site-footer">
        <p>Ethio Telecom · Value Added Services Partners</p>
        <p className="muted">Secure identity via Fayda · Support through your account manager</p>
      </footer>
    </div>
  );
}

export function clearSession() {
  clearToken();
}
