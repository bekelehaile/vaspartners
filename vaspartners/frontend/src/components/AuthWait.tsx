import { ReactNode } from "react";

/**
 * Branded loading / auth-handoff screen (Ethio logo + green spinner).
 * Matches admin panel logo treatment on contact portal wait states.
 */
export function AuthWait({
  title,
  children,
}: {
  title?: string;
  children?: ReactNode;
}) {
  return (
    <main className="auth-wait">
      <div className="auth-wait-card">
        {/* eslint-disable-next-line @next/next/no-img-element */}
        <img
          src="/brand/ethio_logo_full.png"
          alt="Ethio telecom"
          className="auth-wait-logo"
        />
        <div className="spinner" aria-hidden />
        {title ? <h1 className="auth-wait-title">{title}</h1> : null}
        {children}
      </div>
    </main>
  );
}
