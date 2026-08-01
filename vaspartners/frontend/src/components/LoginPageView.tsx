"use client";

import Link from "next/link";
import { FormEvent, useMemo, useState } from "react";
import { useRouter } from "next/navigation";
import { useQueryClient } from "@tanstack/react-query";
import { FaydaIdentityPanel } from "@/components/FaydaIdentityPanel";
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
    if (otpOn) return "Sign in";
    return "VAS Partners";
  }, [otpOn, faydaOn]);

  const subtitle = useMemo(() => {
    if (otpOn && faydaOn) {
      return "Access the Ethio telecom partner portal for Value Added Services.";
    }
    if (otpOn) {
      return "Enter your registered mobile number to continue.";
    }
    return "Secure partner access for Value Added Services. Sign in with your Fayda National ID.";
  }, [otpOn, faydaOn]);

  const faydaOnly = faydaOn && !otpOn;

  function applyIdentityGate(identity: IdentityAuthState, contact: { profile_completed?: boolean }) {
    if (identity.needs_consent && identity.proposal) {
      setProposal(identity.proposal);
      setStep("consent");
      setInfo(null);
      return;
    }
    if (identity.needs_manual_name) {
      setStep("manual_name");
      setInfo("Enter your full name to continue.");
      return;
    }
    router.replace(nextAfterIdentity(contact));
  }

  async function declineAndLogout() {
    setError(null);
    setBusy(true);
    try {
      try {
        await submitIdentityConsent({ action: "decline" });
      } catch {
        // Still sign out even if decline fails (e.g. already cleared).
      }
      await logout();
      setProposal(null);
      setCode("");
      setStep("phone");
      setInfo(null);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Unable to sign out.");
    } finally {
      setBusy(false);
    }
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
            onDecline={() => void declineAndLogout()}
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
    const digits = phone.replace(/\D/g, "");
    const national =
      digits.startsWith("251") && digits.length >= 12
        ? digits.slice(-9)
        : digits.startsWith("0") && digits.length === 10
          ? digits.slice(1)
          : digits;
    if (!/^9\d{8}$/.test(national)) {
      setError("Enter a valid mobile number.");
      return;
    }
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
    await declineAndLogout();
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
      <section className={faydaOnly ? "section login-page login-page--fayda" : "section login-page"}>
        <div className={faydaOnly ? "login-card login-card--fayda" : "login-card"}>
          {faydaOnly ? (
            <>
              <p className="login-kicker">Partner portal</p>
              <h1>{title}</h1>
              <p className="login-lead">{subtitle}</p>
            </>
          ) : (
            <>
              <h1>{title}</h1>
              <p className="muted">{subtitle}</p>
            </>
          )}

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
            <div className={faydaOnly ? "login-fayda-panel" : "login-alt"}>
              {otpOn ? (
                <div className="login-divider" role="separator">
                  <span>Or</span>
                </div>
              ) : null}
              <a
                className={faydaOnly ? "btn-hero login-fayda-cta" : "btn-secondary login-fayda"}
                href={faydaLoginUrl()}
              >
                {faydaOnly ? "Continue with Fayda" : "Sign in with Fayda"}
              </a>
              {faydaOnly ? (
                <ul className="login-trust">
                  <li>Verified with Fayda National ID</li>
                  <li>Your identity details stay protected</li>
                  <li>Session stays active for 30 minutes</li>
                </ul>
              ) : null}
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
      {error && (
        <p className="alert" role="alert">
          {error}
        </p>
      )}
      {/* Same field set as Fayda identity panel; CRM fills name/phone when available. */}
      <FaydaIdentityPanel
        id="login-identity"
        showHeading={false}
        description={null}
        person={{
          name: proposal.name,
          phone_number: proposal.phone,
          email: proposal.email,
          gender: proposal.gender,
          nationality: proposal.nationality,
          birthdate: proposal.birthdate,
          identification_type: proposal.identification_type,
          identification_number: proposal.identification_number,
        }}
      />
      <div className="login-actions" style={{ display: "flex", gap: "0.75rem", flexWrap: "wrap", marginTop: "1rem" }}>
        <button type={asForm ? "submit" : "button"} className="btn-hero" disabled={busy} onClick={asForm ? undefined : onAccept}>
          {busy ? "Saving…" : "Yes, this is me"}
        </button>
        <button type="button" className="btn-secondary" disabled={busy} onClick={onDecline}>
          {busy ? "Signing out…" : "No — sign out"}
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
