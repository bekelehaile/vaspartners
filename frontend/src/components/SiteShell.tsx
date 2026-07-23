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
      <header className="topbar">
        <div className="topbar-inner">
          <Link href={me ? "/portal" : "/"} className="brand-lockup">
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img
              src="/brand/ethio_logo_full.png"
              alt="Ethio Telecom Logo"
              className="brand-logo"
            />
          </Link>

          <nav className="topnav" aria-label="Primary">
            {me ? (
              <div className="portal-header-actions">
                <PortalSettingsMenu />
                {me.profile_completed && <NotificationBell />}
                {onLogout && (
                  <button type="button" className="portal-signout-btn" onClick={onLogout}>
                    Sign out
                  </button>
                )}
              </div>
            ) : (
              <>
                <a href="/#services">Services</a>
                <a href="/#blog">Blog</a>
                <a href="/#gallery">Gallery</a>
                <a href="/#faq">FAQ</a>
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
              ) : (
                <>
                  <a href="/#services" onClick={() => setOpen(false)}>
                    Services
                  </a>
                  <a href="/#blog" onClick={() => setOpen(false)}>
                    Blog
                  </a>
                  <a href="/#gallery" onClick={() => setOpen(false)}>
                    Gallery
                  </a>
                  <a href="/#faq" onClick={() => setOpen(false)}>
                    FAQ
                  </a>
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

      {children}

      <footer className="site-footer">
        <div className="site-footer-inner">
          <p>© {new Date().getFullYear()} Ethio telecom. All rights reserved.</p>
          <div className="footer-social">
            <a href="https://www.ethiotelecom.et/" target="_blank" rel="noreferrer" aria-label="Website">
              Web
            </a>
            <a
              href="https://www.facebook.com/ethiotelecom/"
              target="_blank"
              rel="noreferrer"
              aria-label="Facebook"
            >
              Facebook
            </a>
            <a
              href="https://www.instagram.com/ethiotelecom/"
              target="_blank"
              rel="noreferrer"
              aria-label="Instagram"
            >
              Instagram
            </a>
            <a href="https://t.me/ethio_telecom" target="_blank" rel="noreferrer" aria-label="Telegram">
              Telegram
            </a>
            <a
              href="https://www.linkedin.com/company/ethio-telecom"
              target="_blank"
              rel="noreferrer"
              aria-label="LinkedIn"
            >
              LinkedIn
            </a>
            <a href="https://twitter.com/ethiotelecom" target="_blank" rel="noreferrer" aria-label="X">
              X
            </a>
            <a
              href="https://www.youtube.com/channel/UCW4ZjqFCCFukY94tZO0O5FA"
              target="_blank"
              rel="noreferrer"
              aria-label="YouTube"
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
