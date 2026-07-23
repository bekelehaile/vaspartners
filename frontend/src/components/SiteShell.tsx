"use client";

import Link from "next/link";
import { ReactNode, useState } from "react";
import { NotificationBell } from "@/components/NotificationBell";
import { PortalSettingsMenu } from "@/components/PortalSettingsMenu";
import { Customer, clearToken, faydaLoginUrl } from "@/lib/api";

function LogInIcon() {
  return (
    <svg
      xmlns="http://www.w3.org/2000/svg"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden
    >
      <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4" />
      <polyline points="10 17 15 12 10 7" />
      <line x1="15" x2="3" y1="12" y2="12" />
    </svg>
  );
}

const publicNav = [
  { href: "/#services", label: "Services" },
  { href: "/blog", label: "Blog" },
  { href: "/gallery", label: "Gallery" },
  { href: "/faq", label: "FAQ" },
] as const;

const portalNav = [
  { href: "/portal", label: "My requests" },
  { href: "/portal/services", label: "Services" },
] as const;

export function SiteShell({
  children,
  me,
  onLogout,
}: {
  children: ReactNode;
  me?: Customer | null;
  onLogout?: () => void;
  compact?: boolean;
  landing?: boolean;
}) {
  const [open, setOpen] = useState(false);

  return (
    <div className="site">
      <a href="#main-content" className="skip-link">
        Skip to content
      </a>
      <header className="topbar">
        <div className="topbar-inner">
          <Link href="/" className="brand-lockup" prefetch={false}>
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img
              src="/brand/ethio_logo_full.png"
              alt="Ethio Telecom Logo"
              className="brand-logo"
            />
          </Link>

          <nav className="topnav" aria-label="Primary">
            {me ? (
              <>
                {portalNav.map((item) => (
                  <Link key={item.href} href={item.href}>
                    {item.label}
                  </Link>
                ))}
                <div className="portal-header-actions">
                  <PortalSettingsMenu />
                  {me.profile_completed && <NotificationBell />}
                  {onLogout && (
                    <button type="button" className="portal-signout-btn" onClick={onLogout}>
                      Sign out
                    </button>
                  )}
                </div>
              </>
            ) : (
              <>
                {publicNav.map((item) =>
                  item.href.startsWith("/#") ? (
                    <a key={item.href} href={item.href}>
                      {item.label}
                    </a>
                  ) : (
                    <Link key={item.href} href={item.href}>
                      {item.label}
                    </Link>
                  )
                )}
                <a className="btn-login" href={faydaLoginUrl()}>
                  <LogInIcon />
                  <span>Login</span>
                </a>
              </>
            )}
          </nav>

          <button
            type="button"
            className="mobile-toggle"
            aria-expanded={open}
            aria-label={open ? "Close menu" : "Open menu"}
            onClick={() => setOpen((v) => !v)}
          >
            {open ? "✕" : "☰"}
          </button>
        </div>

        {open && (
          <div className="mobile-sheet">
            <div className="mobile-sheet-card">
              {me ? (
                <>
                  {portalNav.map((item) => (
                    <Link
                      key={item.href}
                      href={item.href}
                      onClick={() => setOpen(false)}
                    >
                      {item.label}
                    </Link>
                  ))}
                  <div className="portal-header-actions portal-header-actions-mobile">
                    <PortalSettingsMenu onNavigate={() => setOpen(false)} />
                    {me.profile_completed && <NotificationBell />}
                    {onLogout && (
                      <button
                        type="button"
                        className="portal-signout-btn"
                        onClick={() => {
                          setOpen(false);
                          onLogout();
                        }}
                      >
                        Sign out
                      </button>
                    )}
                  </div>
                </>
              ) : (
                <>
                  {publicNav.map((item) =>
                    item.href.startsWith("/#") ? (
                      <a key={item.href} href={item.href} onClick={() => setOpen(false)}>
                        {item.label}
                      </a>
                    ) : (
                      <Link
                        key={item.href}
                        href={item.href}
                        onClick={() => setOpen(false)}
                      >
                        {item.label}
                      </Link>
                    )
                  )}
                  <a
                    className="btn-login"
                    href={faydaLoginUrl()}
                    onClick={() => setOpen(false)}
                    style={{ width: "100%" }}
                  >
                    <LogInIcon />
                    <span>Login</span>
                  </a>
                </>
              )}
            </div>
          </div>
        )}
      </header>

      <main id="main-content">{children}</main>

      <footer className="site-footer">
        <div className="site-footer-inner">
          <p>© {new Date().getFullYear()} Ethio telecom. All rights reserved.</p>
          <nav className="footer-links" aria-label="Site">
            <Link href="/blog">Blog</Link>
            <Link href="/gallery">Gallery</Link>
            <Link href="/faq">FAQ</Link>
          </nav>
          <div className="footer-social">
            <a
              href="https://www.ethiotelecom.et/"
              target="_blank"
              rel="noopener noreferrer"
              aria-label="Ethio telecom website"
            >
              Web
            </a>
            <a
              href="https://www.facebook.com/ethiotelecom/"
              target="_blank"
              rel="noopener noreferrer"
              aria-label="Ethio telecom on Facebook"
            >
              Facebook
            </a>
            <a
              href="https://www.instagram.com/ethiotelecom/"
              target="_blank"
              rel="noopener noreferrer"
              aria-label="Ethio telecom on Instagram"
            >
              Instagram
            </a>
            <a
              href="https://t.me/ethio_telecom"
              target="_blank"
              rel="noopener noreferrer"
              aria-label="Ethio telecom on Telegram"
            >
              Telegram
            </a>
            <a
              href="https://www.linkedin.com/company/ethio-telecom"
              target="_blank"
              rel="noopener noreferrer"
              aria-label="Ethio telecom on LinkedIn"
            >
              LinkedIn
            </a>
            <a
              href="https://twitter.com/ethiotelecom"
              target="_blank"
              rel="noopener noreferrer"
              aria-label="Ethio telecom on X"
            >
              X
            </a>
            <a
              href="https://www.youtube.com/channel/UCW4ZjqFCCFukY94tZO0O5FA"
              target="_blank"
              rel="noopener noreferrer"
              aria-label="Ethio telecom on YouTube"
            >
              YouTube
            </a>
          </div>
        </div>
      </footer>
    </div>
  );
}

export function clearSession() {
  clearToken();
}
