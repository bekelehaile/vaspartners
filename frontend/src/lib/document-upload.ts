/** Client-side checks mirroring admin DocumentType config. */
export function parseAcceptedExtensions(acceptedMimes: string): string[] {
  return acceptedMimes
    .split(",")
    .map((p) => p.trim().toLowerCase().replace(/^\./, ""))
    .filter(Boolean);
}

export function validateFileAgainstDocType(
  file: File,
  docType: { accepted_mimes: string; max_size_kb: number; name?: string }
): string | null {
  const extensions = parseAcceptedExtensions(docType.accepted_mimes);
  const name = file.name;
  const dot = name.lastIndexOf(".");
  const ext = dot >= 0 ? name.slice(dot + 1).toLowerCase() : "";

  if (!extensions.length) {
    return "No accepted file types are configured for this document.";
  }
  if (!ext || !extensions.includes(ext)) {
    return `File type must be one of: ${extensions.join(", ")}.`;
  }

  const maxKb = Math.max(1, Number(docType.max_size_kb) || 1);
  const sizeKb = Math.ceil(file.size / 1024);
  if (sizeKb > maxKb) {
    return `File must be ${maxKb} KB or smaller.`;
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
  return status === "completed" || status === "closed";
}
