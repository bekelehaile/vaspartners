/** Client-side checks mirroring admin DocumentType config (strict). */

const EXTENSION_MIME_MAP: Record<string, string[]> = {
  pdf: ["application/pdf"],
  doc: ["application/msword"],
  docx: [
    "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
    "application/zip",
  ],
  xls: ["application/vnd.ms-excel", "application/msexcel"],
  xlsx: [
    "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
    "application/zip",
  ],
  png: ["image/png"],
  jpg: ["image/jpeg", "image/jpg"],
  jpeg: ["image/jpeg", "image/jpg"],
  gif: ["image/gif"],
  webp: ["image/webp"],
  txt: ["text/plain"],
  csv: ["text/csv", "text/plain", "application/csv", "application/vnd.ms-excel"],
  zip: ["application/zip", "application/x-zip-compressed", "multipart/x-zip"],
};

export function parseAcceptedExtensions(acceptedMimes: string): string[] {
  return Array.from(
    new Set(
      acceptedMimes
        .split(/[,\s]+/)
        .map((p) => p.trim().toLowerCase().replace(/^\./, ""))
        .filter(Boolean)
    )
  );
}

export function allowedMimeTypesForExtensions(extensions: string[]): string[] {
  const set = new Set<string>();
  for (const ext of extensions) {
    for (const mime of EXTENSION_MIME_MAP[ext] || []) {
      set.add(mime);
    }
  }
  return Array.from(set);
}

export function validateFileAgainstDocType(
  file: File,
  docType: { accepted_mimes: string; max_size_kb: number; name?: string }
): string | null {
  const label = docType.name?.trim() || "This document";
  const extensions = parseAcceptedExtensions(docType.accepted_mimes);
  const name = file.name || "";
  const dot = name.lastIndexOf(".");
  const ext = dot >= 0 ? name.slice(dot + 1).toLowerCase() : "";

  if (!extensions.length) {
    return `No accepted file types are configured for ${label}.`;
  }

  if (!name.trim()) {
    return `${label}: choose a file with a valid name.`;
  }

  if (name.includes("/") || name.includes("\\") || name.includes("..")) {
    return `${label}: invalid file name.`;
  }

  if (!ext || !extensions.includes(ext)) {
    return `${label} must be one of: ${extensions.join(", ")} (admin config).`;
  }

  if (!file.size || file.size <= 0) {
    return `${label} cannot be an empty file.`;
  }

  const maxKb = Math.max(1, Number(docType.max_size_kb) || 1);
  if (!Number.isFinite(maxKb)) {
    return `${label}: admin max size is not configured correctly.`;
  }

  const maxBytes = maxKb * 1024;
  if (file.size > maxBytes) {
    const sizeKb = Math.ceil(file.size / 1024);
    return `${label} must be ${maxKb} KB or smaller (admin limit). Your file is ${sizeKb} KB.`;
  }

  const allowedMimes = allowedMimeTypesForExtensions(extensions);
  const reported = (file.type || "").toLowerCase().trim();
  if (reported && reported !== "application/octet-stream" && allowedMimes.length) {
    if (!allowedMimes.includes(reported)) {
      return `${label} type "${reported}" is not allowed. Use: ${extensions.join(", ")}.`;
    }
  }

  return null;
}

export function acceptAttrFromMimes(acceptedMimes: string): string {
  return parseAcceptedExtensions(acceptedMimes)
    .map((m) => `.${m}`)
    .join(",");
}

export function documentsLockedStatus(
  status: string | undefined,
  documentsLocked?: boolean
): boolean {
  if (typeof documentsLocked === "boolean") return documentsLocked;
  // Editable only while submitted (open) or sent back (rejected)
  return status !== "open" && status !== "rejected";
}
