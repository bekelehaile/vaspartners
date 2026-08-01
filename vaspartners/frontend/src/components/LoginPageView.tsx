"use client";

import Link from "next/link";
import { FormEvent, useMemo, useState } from "react";
import { useRouter } from "next/navigation";
import { useQueryClient } from "@tanstack/react-query";
import { FaydaIdentityPanel } from "@/components/FaydaIdentityPanel";
import {
  OnboardingProgress,
  SecureSessionNote,
  maskMobileDisplay,
  type OnboardingStepId,
} from "@/components/OnboardingProgress";
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

function nextAfterIdentity(
  contact: { profile_completed?: boolean },
  identity?: IdentityAuthState | null,
) {
  if (identity?.needs_company || !contact.profile_completed) {
    return "/portal/company";
  }
  return "/portal";
}

function progressForStep(step: Step): OnboardingStepId {
  if (step === "consent" || step === "manual_name") return "identity";
  return "verify";
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
    if (step === "code") return "Enter verification code";
    if (step === "consent") return "Confirm your identity";
    if (step === "manual_name") return "Confirm your name";
    return "Secure partner sign-in";
  }, [step]);

  const subtitle = useMemo(() => {
    if (step === "code") {
      return "Enter the one-time code sent to your mobile. Codes expire in five minutes.";
    }
    if (step === "consent") {
      return "Review the identity details returned by Ethio telecom CRM before continuing.";
    }
    if (step === "manual_name") {
      return "We could not match a CRM profile. Enter your legal full name to continue.";
    }
    if (otpOn) {
      return "Verify your mobile, confirm your company TIN, then confirm your identity.";
    }
    return "Continue with your Fayda National ID.";
  }, [otpOn, step]);

  const faydaOnly = faydaOn && !otpOn;

  function applyIdentityGate(
    identity: IdentityAuthState,
    contact: { profile_completed?: boolean },
  ) {
    if (identity.needs_company || !contact.profile_completed) {
      router.replace("/portal/company");
      return;
    }
    if (identity.needs_consent && identity.proposal) {
      setProposal(identity.proposal);
      setStep("consent");
      setInfo(null);
      return;
    }
    if (identity.needs_manual_name) {
      setStep("manual_name");
      setInfo(null);
      return;
    }
    router.replace(nextAfterIdentity(contact, identity));
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
          <section className="section login-page login-page--secure">
            <div className="login-card login-card--secure">
              <OnboardingProgress current="identity" />
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
                    setError(
                      err instanceof Error ? err.message : "Unable to confirm identity.",
                    );
                  } finally {
                    setBusy(false);
                  }
                }}
                onDecline={() => void declineAndLogout()}
              />
              <SecureSessionNote />
            </div>
          </section>
        </SiteShell>
      );
    }

    return (
      <SiteShell me={me} onLogout={() => void logout()} landing>
        <section className="section login-page login-page--secure">
          <div className="login-card login-card--secure">
            <OnboardingProgress
              current={me.profile_completed ? "portal" : "company"}
            />
            <p className="login-kicker">Session active</p>
            <h1>Continue securely</h1>
            <p className="login-lead">
              {me.profile_completed
                ? "Your verified session is ready. Open the partner portal to manage VAS services."
                : "Next, confirm your company TIN with ERCA, then confirm your personal identity."}
            </p>
            <div className="login-actions">
              <Link
                className="btn-hero"
                href={me.profile_completed ? "/portal" : "/portal/company"}
              >
                {me.profile_completed ? "Open partner portal" : "Continue company setup"}
              </Link>
            </div>
            <SecureSessionNote />
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
      setInfo(null);
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
      <section
        className={
          faydaOnly
            ? "section login-page login-page--fayda"
            : "section login-page login-page--secure"
        }
      >
        <div className={faydaOnly ? "login-card login-card--fayda" : "login-card login-card--secure"}>
          {otpOn ? <OnboardingProgress current={progressForStep(step)} /> : null}

          {faydaOnly ? (
            <>
              <p className="login-kicker">Partner portal</p>
              <h1>{title}</h1>
              <p className="login-lead">{subtitle}</p>
            </>
          ) : (
            <>
              <p className="login-kicker">Ethio telecom · VAS Partners</p>
              <h1>{title}</h1>
              <p className="login-lead">{subtitle}</p>
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

          {otpOn && step === "phone" && (
            <div className="login-block">
              <form onSubmit={onRequestOtp} className="login-form" autoComplete="on">
                <label htmlFor="login-phone">Mobile number</label>
                <input
                  id="login-phone"
                  name="phone"
                  type="tel"
                  inputMode="tel"
                  autoComplete="tel"
                  autoFocus
                  placeholder="Enter mobile number"
                  value={phone}
                  onChange={(e) => setPhone(e.target.value)}
                  required
                  disabled={busy}
                  aria-describedby="login-phone-help"
                />
                <p id="login-phone-help" className="field-help">
                  We send a one-time code by SMS. Never share this code.
                </p>
                <button type="submit" className="btn-hero" disabled={busy}>
                  {busy ? "Sending secure code…" : "Send verification code"}
                </button>
              </form>
            </div>
          )}

          {otpOn && step === "code" && (
            <div className="login-block">
              <form onSubmit={onVerifyOtp} className="login-form" autoComplete="one-time-code">
                <div className="login-dest" role="status">
                  <span className="login-dest-label">Code sent to</span>
                  <strong className="login-dest-value">{maskMobileDisplay(phone)}</strong>
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
                </div>
                <label htmlFor="login-code">Verification code</label>
                <input
                  id="login-code"
                  name="code"
                  type="text"
                  inputMode="numeric"
                  autoComplete="one-time-code"
                  autoFocus
                  placeholder="••••••"
                  value={code}
                  onChange={(e) => setCode(e.target.value.replace(/\D/g, "").slice(0, 6))}
                  required
                  minLength={6}
                  maxLength={6}
                  disabled={busy}
                  className="login-otp-input"
                  aria-describedby="login-code-help"
                />
                <p id="login-code-help" className="field-help">
                  Enter the 6-digit code. It expires in 5 minutes.
                </p>
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
              error={error}
              onAccept={() => void onAcceptConsent({ preventDefault() {} } as FormEvent)}
              onDecline={() => void onDeclineConsent()}
              asForm
              onSubmit={onAcceptConsent}
            />
          )}

          {otpOn && step === "manual_name" && (
            <div className="login-block">
              <form onSubmit={onManualName} className="login-form">
                <label htmlFor="login-manual-name">Full legal name</label>
                <input
                  id="login-manual-name"
                  name="name"
                  type="text"
                  autoComplete="name"
                  autoFocus
                  placeholder="As on your official documents"
                  value={manualName}
                  onChange={(e) => setManualName(e.target.value)}
                  required
                  minLength={2}
                  maxLength={120}
                  disabled={busy}
                />
                <p className="field-help">
                  Use your full name. This is not CRM-verified until later confirmation.
                </p>
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
                Continue with Fayda National ID
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

          {otpOn ? (
            <ul className="login-trust login-trust--compact" aria-label="Security assurances">
              <li>One-time SMS code · expires in 5 minutes</li>
              <li>Company TIN verified</li>
              <li>Personal identity confirmed</li>
            </ul>
          ) : null}

          <SecureSessionNote />
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
      <p className="login-kicker">Step 3 · CRM identity</p>
      <h2 className="consent-title">Is this you?</h2>
      <p className="login-lead consent-lead">
        These details were returned securely from Ethio telecom customer records for your
        verified mobile. Confirm only if they are correct.
      </p>
      {error && (
        <p className="alert" role="alert">
          {error}
        </p>
      )}
      <div className="consent-panel">
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
      </div>
      <div className="login-actions consent-actions">
        <button
          type={asForm ? "submit" : "button"}
          className="btn-hero"
          disabled={busy}
          onClick={asForm ? undefined : onAccept}
        >
          {busy ? "Confirming…" : "Yes — this is my identity"}
        </button>
        <button type="button" className="btn-secondary" disabled={busy} onClick={onDecline}>
          {busy ? "Signing out…" : "No — sign out"}
        </button>
      </div>
      <p className="field-help">
        If these details are wrong, sign out and contact Ethio telecom. Do not confirm another
        person’s identity.
      </p>
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

  return <div className="login-block">{body}</div>;
}
