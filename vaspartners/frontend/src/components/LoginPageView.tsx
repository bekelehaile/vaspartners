"use client";

import Link from "next/link";
import { FormEvent, useMemo, useState } from "react";
import { useRouter } from "next/navigation";
import { useQueryClient } from "@tanstack/react-query";
import { SiteShell } from "@/components/SiteShell";
import { useAuthConfig } from "@/hooks/use-auth-config";
import { useContact, useLogout } from "@/hooks/use-contact";
import {
  faydaLoginUrl,
  requestPortalOtp,
  setToken,
  verifyPortalOtp,
} from "@/lib/api";
import { queryKeys } from "@/lib/query-keys";

type Step = "phone" | "code";

export function LoginPageView() {
  const router = useRouter();
  const queryClient = useQueryClient();
  const { data: me = null } = useContact();
  const logout = useLogout();
  const { data: authConfig } = useAuthConfig();

  const faydaOn = authConfig?.fayda_enabled ?? true;
  const otpOn = authConfig?.phone_otp_enabled ?? true;

  const [step, setStep] = useState<Step>("phone");
  const [phone, setPhone] = useState("");
  const [code, setCode] = useState("");
  const [name, setName] = useState("");
  const [needsName, setNeedsName] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [info, setInfo] = useState<string | null>(null);

  const title = useMemo(() => {
    if (otpOn && faydaOn) return "Sign in to VAS Partners";
    if (otpOn) return "Sign in with phone";
    return "Sign in with Fayda";
  }, [otpOn, faydaOn]);

  if (me) {
    return (
      <SiteShell me={me} onLogout={() => void logout()} landing>
        <section className="section login-page">
          <div className="login-card">
            <h1>You are signed in</h1>
            <p className="muted">Continue to your portal or company setup.</p>
            <div className="login-actions">
              <Link
                className="btn-hero"
                href={me.profile_completed ? "/portal" : "/portal/company"}
              >
                {me.profile_completed ? "Go to portal" : "Complete company setup"}
              </Link>
            </div>
          </div>
        </section>
      </SiteShell>
    );
  }

  async function onRequestOtp(e: FormEvent) {
    e.preventDefault();
    setError(null);
    setInfo(null);
    setBusy(true);
    try {
      const res = await requestPortalOtp(phone);
      setPhone(res.data.phone);
      setNeedsName(res.data.needs_name);
      setStep("code");
      setInfo("We sent a 6-digit code by SMS. It expires in 5 minutes.");
    } catch (err) {
      setError(err instanceof Error ? err.message : "Unable to send code.");
    } finally {
      setBusy(false);
    }
  }

  async function onVerifyOtp(e: FormEvent) {
    e.preventDefault();
    setError(null);
    setBusy(true);
    try {
      const res = await verifyPortalOtp({
        phone,
        code,
        name: needsName ? name : undefined,
      });
      setToken(res.data.token);
      queryClient.setQueryData(queryKeys.contact.me, res.data.contact);
      const next = res.data.contact.profile_completed
        ? "/portal"
        : "/portal/company";
      router.replace(next);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Unable to verify code.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <SiteShell me={null} landing>
      <section className="section login-page">
        <div className="login-card">
          <h1>{title}</h1>
          <p className="muted">
            Partner portal access for Value Added Services.
          </p>

          {authConfig?.note && (
            <p className="alert alert-info" role="status">
              {authConfig.note}
            </p>
          )}

          {error && (
            <p className="alert" role="alert">
              {error}
            </p>
          )}
          {info && !error && (
            <p className="alert alert-success" role="status">
              {info}
            </p>
          )}

          {otpOn && (
            <div className="login-block">
              {step === "phone" ? (
                <form onSubmit={onRequestOtp} className="login-form">
                  <label htmlFor="login-phone">Mobile number</label>
                  <input
                    id="login-phone"
                    name="phone"
                    type="tel"
                    inputMode="tel"
                    autoComplete="tel"
                    placeholder="09xxxxxxxx"
                    value={phone}
                    onChange={(e) => setPhone(e.target.value)}
                    required
                    disabled={busy}
                  />
                  <button type="submit" className="btn-hero" disabled={busy}>
                    {busy ? "Sending…" : "Send verification code"}
                  </button>
                </form>
              ) : (
                <form onSubmit={onVerifyOtp} className="login-form">
                  <p className="muted login-phone-hint">
                    Code sent to <strong>{phone}</strong>{" "}
                    <button
                      type="button"
                      className="linkish"
                      onClick={() => {
                        setStep("phone");
                        setCode("");
                        setInfo(null);
                        setError(null);
                      }}
                      disabled={busy}
                    >
                      Change number
                    </button>
                  </p>
                  {needsName && (
                    <>
                      <label htmlFor="login-name">Full name</label>
                      <input
                        id="login-name"
                        name="name"
                        type="text"
                        autoComplete="name"
                        placeholder="Your full name"
                        value={name}
                        onChange={(e) => setName(e.target.value)}
                        required
                        disabled={busy}
                      />
                    </>
                  )}
                  <label htmlFor="login-code">Verification code</label>
                  <input
                    id="login-code"
                    name="code"
                    type="text"
                    inputMode="numeric"
                    autoComplete="one-time-code"
                    placeholder="6-digit code"
                    value={code}
                    onChange={(e) =>
                      setCode(e.target.value.replace(/\D/g, "").slice(0, 6))
                    }
                    required
                    maxLength={6}
                    disabled={busy}
                  />
                  <button type="submit" className="btn-hero" disabled={busy}>
                    {busy ? "Signing in…" : "Verify and continue"}
                  </button>
                </form>
              )}
            </div>
          )}

          {otpOn && faydaOn && <div className="login-divider">or</div>}

          {faydaOn && (
            <div className="login-block">
              <a className="btn-hero-ghost login-fayda" href={faydaLoginUrl()}>
                Continue with Fayda
              </a>
              <p className="muted login-fayda-note">
                National ID sign-in when Fayda / eSignet is available.
              </p>
            </div>
          )}

          {!otpOn && !faydaOn && (
            <p className="alert">
              Partner sign-in is temporarily unavailable. Please try again later.
            </p>
          )}

          <p className="muted login-back">
            <Link href="/">Back to home</Link>
          </p>
        </div>
      </section>
    </SiteShell>
  );
}
