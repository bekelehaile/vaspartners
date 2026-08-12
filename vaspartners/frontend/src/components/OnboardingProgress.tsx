"use client";

export type OnboardingStepId = "verify" | "company" | "identity" | "portal";

const STEPS: { id: OnboardingStepId; label: string; short: string }[] = [
  { id: "verify", label: "Verify mobile", short: "Verify" },
  { id: "company", label: "Company TIN", short: "Company" },
  { id: "identity", label: "Confirm identity", short: "Identity" },
  { id: "portal", label: "Portal access", short: "Portal" },
];

function stepIndex(id: OnboardingStepId): number {
  return STEPS.findIndex((s) => s.id === id);
}

/**
 * Institutional progress rail for partner onboarding (OTP → TIN → CRM → portal).
 */
export function OnboardingProgress({
  current,
  className = "",
}: {
  current: OnboardingStepId;
  className?: string;
}) {
  const active = stepIndex(current);

  return (
    <nav
      className={`onboard-progress ${className}`.trim()}
      aria-label="Secure onboarding progress"
    >
      <ol className="onboard-progress-list">
        {STEPS.map((step, index) => {
          const state =
            index < active ? "done" : index === active ? "current" : "upcoming";
          return (
            <li key={step.id} className={`onboard-progress-item is-${state}`}>
              <span className="onboard-progress-marker" aria-hidden="true">
                {state === "done" ? (
                  <svg viewBox="0 0 16 16" width="12" height="12" fill="none">
                    <path
                      d="M3.5 8.5 6.5 11.5 12.5 4.5"
                      stroke="currentColor"
                      strokeWidth="2"
                      strokeLinecap="round"
                      strokeLinejoin="round"
                    />
                  </svg>
                ) : (
                  <span className="onboard-progress-num">{index + 1}</span>
                )}
              </span>
              <span className="onboard-progress-label">
                <span className="onboard-progress-label-full">{step.label}</span>
                <span className="onboard-progress-label-short">{step.short}</span>
              </span>
              {index < STEPS.length - 1 ? (
                <span className="onboard-progress-connector" aria-hidden="true" />
              ) : null}
            </li>
          );
        })}
      </ol>
    </nav>
  );
}

export function SecureSessionNote({
  children = "Official Ethio telecom VAS Partners portal. Do not share verification codes with anyone.",
}: {
  children?: React.ReactNode;
}) {
  return (
    <p className="secure-session-note" role="note">
      <span className="secure-session-icon" aria-hidden="true">
        <svg viewBox="0 0 20 20" width="14" height="14" fill="none">
          <path
            d="M10 2.5 4.5 5v4.2c0 3.4 2.3 6.5 5.5 7.3 3.2-.8 5.5-3.9 5.5-7.3V5L10 2.5Z"
            stroke="currentColor"
            strokeWidth="1.5"
            strokeLinejoin="round"
          />
          <path
            d="M7.75 10.1 9.2 11.55 12.4 8.35"
            stroke="currentColor"
            strokeWidth="1.5"
            strokeLinecap="round"
            strokeLinejoin="round"
          />
        </svg>
      </span>
      <span>{children}</span>
    </p>
  );
}

/** Mask local mobile for display (e.g. 09••••1756). */
export function maskMobileDisplay(raw: string): string {
  const digits = raw.replace(/\D/g, "");
  const national =
    digits.startsWith("251") && digits.length >= 12
      ? digits.slice(-9)
      : digits.startsWith("0") && digits.length === 10
        ? digits.slice(1)
        : digits.length === 9
          ? digits
          : digits;
  if (!/^[89]\d{8}$/.test(national)) {
    return raw || "—";
  }
  return `0${national.slice(0, 2)}••••${national.slice(-4)}`;
}
