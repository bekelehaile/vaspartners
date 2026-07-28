"use client";

import { useQuery } from "@tanstack/react-query";
import { faydaLoginUrl, fetchAuthConfig, type AuthConfig } from "@/lib/api";
import { queryKeys } from "@/lib/query-keys";

const fallback: AuthConfig = {
  auth_mode: "both",
  fayda_enabled: true,
  phone_otp_enabled: true,
  note: null,
};

export function useAuthConfig() {
  return useQuery({
    queryKey: queryKeys.authConfig,
    queryFn: fetchAuthConfig,
    staleTime: 60_000,
    placeholderData: fallback,
  });
}

/** Prefer /login so phone OTP or Fayda can be chosen from App settings. */
export function portalLoginHref(config?: AuthConfig | null): string {
  if (config && config.fayda_enabled && !config.phone_otp_enabled) {
    return faydaLoginUrl();
  }
  return "/login";
}
