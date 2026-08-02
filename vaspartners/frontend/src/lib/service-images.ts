/** Catalog service media + description helpers for the public website. */

const BY_SLUG: Record<string, string> = {
  "sms-premium": "/img/sms_premium.svg",
  "sms-non-premium": "/img/sms_np.svg",
  "voice-premium": "/img/voice_premium.svg",
  "voice-non-premium": "/img/voice_np.svg",
  collocation: "/img/collocation.svg",
  "m2m-machine-to-machine": "/img/m2m.svg",
  "visp-virtual-internet-service-provider": "/img/visp.svg",
  crbt: "/img/crbt.svg",
  "corporate-crbt": "/img/corporate_crbt.svg",
  "ussd-premium": "/img/ussd_premium.svg",
  "ussd-non-premium": "/img/ussd_np.svg",
  obd: "/img/obd.svg",
  api: "/img/api.svg",
  "mo-mobile-originating": "/img/mo.svg",
  "mt-mobile-terminated-premium": "/img/mt.svg",
  "a2p-application-to-person": "/img/a2p.svg",
  "device-insurance": "/img/device_insurance.svg",
  "ethio-avaya-spaces": "/img/api.svg",
  "public-ip": "/img/payment_api.svg",
  "white-list": "/img/a2p.svg",
  "get-pass-request": "/img/device_insurance.svg",
  "merchant-acoount": "/img/payment_api.svg",
  startup: "/img/services.svg",
};

/** Homepage feature order fallback when sort_order ties. */
export const LANDING_SERVICE_ORDER: string[] = [
  "sms-premium",
  "sms-non-premium",
  "voice-premium",
  "collocation",
  "m2m-machine-to-machine",
  "visp-virtual-internet-service-provider",
  "voice-non-premium",
  "crbt",
  "corporate-crbt",
  "ussd-premium",
  "ussd-non-premium",
  "obd",
  "api",
  "mo-mobile-originating",
  "public-ip",
  "white-list",
  "get-pass-request",
];

const HIDDEN_ON_LANDING = new Set(["startup", "merchant-acoount"]);

type ServiceMedia = {
  slug?: string | null;
  image_url?: string | null;
};

/** Prefer admin-uploaded image; fall back to legacy slug artwork. */
export function serviceImageUrl(service: ServiceMedia | string | null | undefined): string {
  if (service && typeof service === "object") {
    if (service.image_url?.trim()) {
      return service.image_url.trim();
    }
    return legacySlugImage(service.slug);
  }

  return legacySlugImage(typeof service === "string" ? service : null);
}

function legacySlugImage(slug: string | null | undefined): string {
  if (!slug) return "/img/services.svg";
  return BY_SLUG[slug] ?? "/img/services.svg";
}

export function sortServicesForLanding<
  T extends { slug: string; name: string; sort_order?: number | null },
>(services: T[]): T[] {
  const rank = new Map(LANDING_SERVICE_ORDER.map((slug, i) => [slug, i]));
  return [...services]
    .filter((s) => !HIDDEN_ON_LANDING.has(s.slug))
    .sort((a, b) => {
      const sa = a.sort_order ?? 1000;
      const sb = b.sort_order ?? 1000;
      if (sa !== sb) return sa - sb;
      const ra = rank.has(a.slug) ? rank.get(a.slug)! : 1000;
      const rb = rank.has(b.slug) ? rank.get(b.slug)! : 1000;
      if (ra !== rb) return ra - rb;
      return a.name.localeCompare(b.name);
    });
}

/** Legacy descriptions often use literal `rn` instead of newlines. */
export function formatServiceDescription(raw: string | null | undefined): string {
  if (!raw?.trim()) {
    return "Value added service available through the VAS Partners portal.";
  }
  return raw
    .replace(/\r\n/g, "\n")
    .replace(/\brn(?=[A-Z0-9])/g, "\n")
    .replace(/\s*rn\s*/g, "\n")
    .replace(/\n{3,}/g, "\n\n")
    .trim();
}

export function descriptionLooksLikeHtml(raw: string | null | undefined): boolean {
  return !!raw && /<\/?[a-z][\s\S]*>/i.test(raw);
}

/** Light HTML sanitize for admin-authored service descriptions (client-side). */
export function sanitizeServiceHtml(html: string): string {
  const cleaned = html
    .replace(/<script[\s\S]*?>[\s\S]*?<\/script>/gi, "")
    .replace(/<iframe[\s\S]*?>[\s\S]*?<\/iframe>/gi, "")
    .replace(/<object[\s\S]*?>[\s\S]*?<\/object>/gi, "")
    .replace(/<embed[\s\S]*?>/gi, "")
    .replace(/\son\w+\s*=\s*("[^"]*"|'[^']*'|[^\s>]+)/gi, "")
    .replace(/\shref\s*=\s*(['"])\s*javascript:[^'"]*\1/gi, ' href="#"');

  if (typeof window === "undefined") {
    return cleaned;
  }

  try {
    const doc = new DOMParser().parseFromString(cleaned, "text/html");
    doc.querySelectorAll("script,iframe,object,embed").forEach((el) => el.remove());
    doc.querySelectorAll("*").forEach((el) => {
      [...el.attributes].forEach((attr) => {
        if (
          attr.name.startsWith("on") ||
          (attr.name === "href" && /^\s*javascript:/i.test(attr.value))
        ) {
          el.removeAttribute(attr.name);
        }
      });
    });
    return doc.body.innerHTML;
  } catch {
    return cleaned;
  }
}
