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

/** Split "MT/ Mobile terminated Premium" → code + polished title. */
export function splitServiceDisplayName(name: string): {
  code: string | null;
  title: string;
} {
  const trimmed = name.trim();
  if (!trimmed) {
    return { code: null, title: "Service" };
  }

  const match = trimmed.match(/^([A-Za-z0-9]{2,12})\s*\/\s*(.+)$/);
  if (match) {
    return {
      code: match[1].toUpperCase(),
      title: titleCaseServiceName(match[2]),
    };
  }

  return { code: null, title: titleCaseServiceName(trimmed) };
}

function titleCaseServiceName(value: string): string {
  const small = new Set(["and", "or", "of", "to", "for", "the", "a", "an", "in", "on"]);
  return value
    .trim()
    .replace(/\s+/g, " ")
    .split(" ")
    .map((word, index) => {
      if (/^[A-Z0-9]{2,}$/.test(word)) {
        return word;
      }
      const lower = word.toLowerCase();
      if (index > 0 && small.has(lower)) {
        return lower;
      }
      return lower.charAt(0).toUpperCase() + lower.slice(1);
    })
    .join(" ");
}

/** Legacy descriptions often use literal `rn` instead of newlines. Never split "internet". */
export function formatServiceDescription(raw: string | null | undefined): string {
  if (!raw?.trim()) {
    return "";
  }
  return raw
    .replace(/<[^>]+>/g, " ")
    .replace(/\r\n/g, "\n")
    .replace(/rn(?=rn)/g, "\n")
    .replace(/\s*rn(?=\d+[).])/g, "\n")
    .replace(/\s+rn\s+/g, "\n")
    .replace(/\s*rn(?=[A-Z0-9(])/g, "\n")
    .replace(/(?<=[A-Z][A-Z])rn/g, "\n")
    .replace(/(?<=[a-z)])rn(?=[A-Z(])/g, "\n")
    .replace(/\n{3,}/g, "\n\n")
    .replace(/[ \t]+\n/g, "\n")
    .trim();
}

/**
 * Clean legacy catalogue copy for public display / editor seed.
 * Drops redundant titles like "SMS Premium" and "SMS Premium service: -".
 */
export function polishServiceDescription(
  raw: string | null | undefined,
  serviceName?: string | null,
): string {
  let text = formatServiceDescription(raw);
  if (!text) {
    return "Details for this service will be published here soon.";
  }

  text = text
    .replace(/Value added ervice/gi, "Value added service")
    .replace(
      /VASP\s*\(\s*Value\s+added\s+service\s+provider\s*\)/gi,
      "VASP (Value Added Service Provider)",
    )
    .replace(/\bethio telecom\b/gi, "Ethio telecom")
    .replace(/information['’]s\b/gi, "information")
    .replace(/\s+:\s*-\s*/g, ": ")
    .replace(/\s{2,}/g, " ");

  const lines = text
    .split(/\n+/)
    .map((line) => line.trim())
    .filter((line) => line && !/^[sS]$/.test(line));

  const name = (serviceName ?? "").trim();
  const nameRoot = name.replace(/\s*\/\s*.*$/, "").trim();
  const dropTitle = (line: string): boolean => {
    if (line.length > 48) return false;
    const lower = line.toLowerCase();
    return (
      /^[A-Z0-9][A-Z0-9\s\-()/]{0,24}$/.test(line) ||
      lower === name.toLowerCase() ||
      (!!nameRoot && lower === nameRoot.toLowerCase())
    );
  };

  while (lines.length > 1 && dropTitle(lines[0])) {
    lines.shift();
  }

  let body = lines.join("\n\n");

  // "SMS Premium service: -" / "MT (Mobile terminated): means ..."
  body = body.replace(
    /^[\w][^.\n]{0,90}?(?:\bservice)?\s*:\s*(?:-\s*)?/i,
    "",
  );
  body = body.replace(/^means\s+(an?\s+)/i, (_, art: string) =>
    art.charAt(0).toUpperCase() + art.slice(1),
  );
  body = body.replace(/^means\s+/i, "");
  body = body.replace(/^is\s+(an?\s+)/i, (_, art: string) =>
    art.charAt(0).toUpperCase() + art.slice(1),
  );
  body = body.replace(/^is\s+/i, "");

  body = body
    .replace(
      /\s*Legal\s+requirements?(?:\s+to\s+get[^\n]*)?/gi,
      "\n\nLegal requirements\n",
    )
    .replace(/\n{3,}/g, "\n\n")
    .trim();

  if (!body) {
    return "Details for this service will be published here soon.";
  }

  // Capitalize first sentence character.
  return body.charAt(0).toUpperCase() + body.slice(1);
}

/** Turn polished plain text into paragraph / list blocks for the detail page. */
export function serviceDescriptionBlocks(text: string): Array<
  | { type: "p"; text: string }
  | { type: "h"; text: string }
  | { type: "ol"; items: string[] }
> {
  const lines = text.split(/\n+/).map((l) => l.trim()).filter(Boolean);
  const blocks: Array<
    | { type: "p"; text: string }
    | { type: "h"; text: string }
    | { type: "ol"; items: string[] }
  > = [];
  let list: string[] = [];

  const flushList = () => {
    if (list.length) {
      blocks.push({ type: "ol", items: list });
      list = [];
    }
  };

  for (const line of lines) {
    const numbered = line.match(/^\d+[).]\s*(.+)$/);
    if (numbered) {
      list.push(numbered[1]);
      continue;
    }
    flushList();
    if (/^legal requirements?$/i.test(line)) {
      blocks.push({ type: "h", text: "Legal requirements" });
      continue;
    }
    blocks.push({ type: "p", text: line });
  }
  flushList();
  return blocks;
}

export function descriptionLooksLikeHtml(raw: string | null | undefined): boolean {
  // Ignore trivial wrappers leftover from imports.
  const compact = (raw ?? "").replace(/\s+/g, " ").trim();
  if (!compact) return false;
  if (/^<p>.*<\/p>$/i.test(compact) && (compact.match(/<[a-z]/gi) ?? []).length <= 2) {
    // Single paragraph of mostly plain legacy text — treat as plain for polishing.
    const inner = compact.replace(/^<p>/i, "").replace(/<\/p>$/i, "");
    if (!/<(ul|ol|li|strong|em|a|h\d)\b/i.test(inner)) {
      return false;
    }
  }
  return /<\/?[a-z][\s\S]*>/i.test(compact);
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

/** Convert polished plain text into simple HTML for the Filament rich editor. */
export function polishedDescriptionToHtml(text: string): string {
  const blocks = serviceDescriptionBlocks(text);
  return blocks
    .map((block) => {
      if (block.type === "h") {
        return `<h3>${escapeHtml(block.text)}</h3>`;
      }
      if (block.type === "ol") {
        return `<ol>${block.items.map((item) => `<li>${escapeHtml(item)}</li>`).join("")}</ol>`;
      }
      return `<p>${escapeHtml(block.text)}</p>`;
    })
    .join("");
}

function escapeHtml(value: string): string {
  return value
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");
}
