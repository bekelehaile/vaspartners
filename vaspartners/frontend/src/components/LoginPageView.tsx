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
  submitIdentityConsent,
  verifyPortalOtp,
  type IdentityConsentProposal,
  type IdentityAuthState,
} from "@/lib/api";
import { queryKeys } from "@/lib/query-keys";

type Step = "phone" | "code" | "consent" | "manual_name";

function nextAfterIdentity(contact: { profile_completed?: boolean }) {
  return contact.profile_completed ? "/portal" : "/portal/company";
}

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
  const [manualName, setManualName] = useState("");
  const [proposal, setProposal] = useState<IdentityConsentProposal | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [info, setInfo] = useState<string | null>(null);

  const title = useMemo(() => {
    if (otpOn && faydaOn) return "Sign in to VAS Partners";
    if (otpOn) return "Sign in with phone";
    return "Sign in with Fayda";
  }, [otpOn, faydaOn]);

  function applyIdentityGate(identity: IdentityAuthState, contact: { profile_completed?: boolean }) {
    if (identity.needs_consent && identity.proposal) {
      setProposal(identity.proposal);
      setStep("consent");
      setInfo("Confirm your Ethio telecom CRM identity to continue.");
      return;
    }
    if (identity.needs_manual_name) {
      setStep("manual_name");
      setInfo("We could not match this number in CRM. Enter your full name to continue.");
      return;
    }
    router.replace(nextAfterIdentity(contact));
  }

  // Already signed in — finish identity if still needed.
  if (me && step !== "consent" && step !== "manual_name") {
    if (me.needs_identity_consent && me.identity_proposal) {
      return (
        <SiteShell me={me} onLogout={() => void logout()} landing>
          <ConsentCard
            proposal={me.identity_proposal}
            busy={busy}
            error={error}
            onAccept={async () => {
              setBusy(true);
              setError(null);
              try {
                const res = await submitIdentityConsent({ action: "accept" });
                queryClient.setQueryData(queryKeys.contact.me, res.data.contact);
                router.replace(nextAfterIdentity(res.data.contact));
              } catch (err) {
                setError(err instanceof Error ? err.message : "Unable to confirm identity.");
              } finally {
                setBusy(false);
              }
            }}
            onDecline={async () => {
              setBusy(true);
              setError(null);
              try {
                await submitIdentityConsent({ action: "decline" });
                setStep("manual_name");
                setProposal(null);
              } catch (err) {
                setError(err instanceof Error ? err.message : "Unable to decline.");
              } finally {
                setBusy(false);
              }
            }}
          />
        </SiteShell>
      );
    }

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
      const res = await verifyPortalOtp({ phone, code });
      setToken(res.data.token);
      queryClient.setQueryData(queryKeys.contact.me, res.data.contact);
      applyIdentityGate(res.data.identity, res.data.contact);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Unable to verify code.");
    } finally {
      setBusy(false);
    }
  }

  async function onAcceptConsent(e: FormEvent) {
    e.preventDefault();
    setError(null);
    setBusy(true);
    try {
      const res = await submitIdentityConsent({ action: "accept" });
      queryClient.setQueryData(queryKeys.contact.me, res.data.contact);
      router.replace(nextAfterIdentity(res.data.contact));
    } catch (err) {
      setError(err instanceof Error ? err.message : "Unable to confirm identity.");
    } finally {
      setBusy(false);
    }
  }

  async function onDeclineConsent() {
    setError(null);
    setBusy(true);
    try {
      await submitIdentityConsent({ action: "decline" });
      setProposal(null);
      setStep("manual_name");
      setInfo("Enter your full name to continue. Company setup only needs name, TIN, and address.");
    } catch (err) {
      setError(err instanceof Error ? err.message : "Unable to decline.");
    } finally {
      setBusy(false);
    }
  }

  async function onManualName(e: FormEvent) {
    e.preventDefault();
    setError(null);
    setBusy(true);
    try {
      const res = await submitIdentityConsent({
        action: "decline",
        name: manualName,
      });
      queryClient.setQueryData(queryKeys.contact.me, res.data.contact);
      router.replace(nextAfterIdentity(res.data.contact));
    } catch (err) {
      setError(err instanceof Error ? err.message : "Unable to save name.");
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

          {otpOn && step === "phone" && (
            <div className="login-block">
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
            </div>
          )}

          {otpOn && step === "code" && (
            <div className="login-block">
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
                <label htmlFor="login-code">Verification code</label>
                <input
                  id="login-code"
                  name="code"
                  type="text"
                  inputMode="numeric"
                  autoComplete="one-time-code"
                  placeholder="6-digit code"
                  value={code}
                  onChange={(e) => setCode(e.target.value.replace(/\D/g, "").slice(0, 6))}
                  required
                  minLength={6}
                  maxLength={6}
                  disabled={busy}
                />
                <button type="submit" className="btn-hero" disabled={busy || code.length !== 6}>
                  {busy ? "Verifying…" : "Verify and continue"}
                </button>
              </form>
            </div>
          )}

          {otpOn && step === "consent" && proposal && (
            <ConsentCard
              proposal={proposal}
              busy={busy}
              error={null}
              onAccept={() => void onAcceptConsent({ preventDefault() {} } as FormEvent)}
              onDecline={() => void onDeclineConsent()}
              asForm
              onSubmit={onAcceptConsent}
            />
          )}

          {otpOn && step === "manual_name" && (
            <div className="login-block">
              <form onSubmit={onManualName} className="login-form">
                <label htmlFor="login-manual-name">Full name</label>
                <input
                  id="login-manual-name"
                  name="name"
                  type="text"
                  autoComplete="name"
                  placeholder="Your full name"
                  value={manualName}
                  onChange={(e) => setManualName(e.target.value)}
                  required
                  minLength={2}
                  disabled={busy}
                />
                <button type="submit" className="btn-hero" disabled={busy}>
                  {busy ? "Saving…" : "Continue"}
                </button>
              </form>
            </div>
          )}

          {faydaOn && step === "phone" && (
            <div className="login-alt">
              {otpOn ? <p className="muted">Or</p> : null}
              <a className="btn-secondary" href={faydaLoginUrl()}>
                Sign in with Fayda
              </a>
            </div>
          )}
        </div>
      </section>
    </SiteShell>
  );
}

function ConsentCard({
  proposal,
  busy,
  error,
  onAccept,
  onDecline,
  asForm,
  onSubmit,
}: {
  proposal: IdentityConsentProposal;
  busy: boolean;
  error: string | null;
  onAccept: () => void;
  onDecline: () => void;
  asForm?: boolean;
  onSubmit?: (e: FormEvent) => void;
}) {
  const body = (
    <>
      <h2 style={{ marginTop: 0 }}>Confirm your identity</h2>
      <p className="muted">
        We found this profile in Ethio telecom CRM for your mobile number.
        Confirm to verify your identity (same trust path as Fayda). Next time you
        sign in, we will not ask again.
      </p>
      {error && (
        <p className="alert" role="alert">
          {error}
        </p>
      )}
      <dl className="fayda-dl" style={{ marginBottom: "1rem" }}>
        <div>
          <dt>Name</dt>
          <dd>{proposal.name || "—"}</dd>
        </div>
        <div>
          <dt>Phone</dt>
          <dd>{proposal.phone || "—"}</dd>
        </div>
        {proposal.primary_offer_name ? (
          <div>
            <dt>Offer</dt>
            <dd>{proposal.primary_offer_name}</dd>
          </div>
        ) : null}
        {proposal.customer_type ? (
          <div>
            <dt>Customer type</dt>
            <dd>{proposal.customer_type}</dd>
          </div>
        ) : null}
      </dl>
      <div className="login-actions" style={{ display: "flex", gap: "0.75rem", flexWrap: "wrap" }}>
        <button type={asForm ? "submit" : "button"} className="btn-hero" disabled={busy} onClick={asForm ? undefined : onAccept}>
          {busy ? "Saving…" : "Yes, this is me"}
        </button>
        <button type="button" className="btn-secondary" disabled={busy} onClick={onDecline}>
          Not me — enter name
        </button>
      </div>
    </>
  );

  if (asForm && onSubmit) {
    return (
      <div className="login-block">
        <form onSubmit={onSubmit} className="login-form">
          {body}
        </form>
      </div>
    );
  }

  return (
    <section className="section login-page">
      <div className="login-card">{body}</div>
    </section>
  );
}
