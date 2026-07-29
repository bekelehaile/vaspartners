/** Ethiopian TIN (Ministry of Revenues / ERCA): exactly 10 digits. */

export const ETHIOPIAN_TIN_LENGTH = 10;

export function normalizeEthiopianTin(raw: string): string {
  return String(raw ?? "").replace(/\D+/g, "");
}

export function isValidEthiopianTin(raw: string): boolean {
  const digits = normalizeEthiopianTin(raw);
  if (digits.length !== ETHIOPIAN_TIN_LENGTH) return false;
  if (!/^\d{10}$/.test(digits)) return false;
  if (/^(\d)\1{9}$/.test(digits)) return false;
  if (digits === "0123456789" || digits === "1234567890") return false;
  return true;
}

export const ETHIOPIAN_TIN_MESSAGE =
  "Enter a valid Ethiopian TIN: exactly 10 digits (Ministry of Revenues / ERCA).";
