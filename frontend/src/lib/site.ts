/** Public site identity & SEO defaults (Ethio telecom VAS Partners). */

export const siteConfig = {
  name: "VAS Partners",
  shortName: "VAS Partners",
  orgName: "Ethio telecom",
  titleTemplate: "%s | VAS Partners · Ethio telecom",
  defaultTitle: "VAS Partners | Ethio telecom",
  description:
    "Official Ethio telecom VAS Partners portal. Request Value Added Services, upload documents, and track approvals online with Fayda National ID.",
  keywords: [
    "Ethio telecom",
    "VAS Partners",
    "Value Added Services",
    "Ethiopia",
    "Fayda",
    "partner portal",
    "service request",
  ],
  locale: "en_ET",
  language: "en",
  twitterHandle: "@ethiotelecom",
  sameAs: [
    "https://www.ethiotelecom.et/",
    "https://www.facebook.com/ethiotelecom/",
    "https://www.instagram.com/ethiotelecom/",
    "https://t.me/ethio_telecom",
    "https://www.linkedin.com/company/ethio-telecom",
    "https://twitter.com/ethiotelecom",
    "https://www.youtube.com/channel/UCW4ZjqFCCFukY94tZO0O5FA",
  ],
} as const;

export function getSiteUrl(): string {
  const raw =
    process.env.NEXT_PUBLIC_SITE_URL ||
    process.env.VERCEL_URL ||
    "http://localhost:3000";
  const withProtocol = raw.startsWith("http") ? raw : `https://${raw}`;
  return withProtocol.replace(/\/$/, "");
}

export function getApiBaseUrl(): string {
  return (process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000/api/v1").replace(
    /\/$/,
    ""
  );
}

export function absoluteUrl(path = "/"): string {
  const base = getSiteUrl();
  if (!path || path === "/") return `${base}/`;
  return `${base}${path.startsWith("/") ? path : `/${path}`}`;
}
