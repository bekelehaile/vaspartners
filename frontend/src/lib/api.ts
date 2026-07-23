const API = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000/api/v1";

export type Client = {
  public_id: string;
  name: string;
  company_name?: string | null;
  email?: string | null;
  phone?: string | null;
  profile_completed_at?: string | null;
};

export type Service = {
  id: number;
  name: string;
  slug: string;
  description?: string | null;
  category?: { id: number; name: string; slug: string };
  requisitions?: { id: number; name: string; slug: string }[];
};

export type Ticket = {
  id: number;
  public_id: string;
  tt_number: string;
  status: "open" | "in_progress" | "completed" | "closed" | "rejected";
  document_review_status?: string;
  description?: string | null;
  building?: string | null;
  location?: string | null;
  service?: { id: number; name: string };
  requisition?: { id: number; name: string };
  created_at: string;
  updated_at?: string;
  status_histories?: {
    from_status?: string | null;
    to_status: string;
    note?: string | null;
    created_at: string;
  }[];
  documents?: {
    id: number;
    original_name: string;
    document_type?: { name: string };
  }[];
};

export type DocumentRequirement = {
  id: number;
  is_required: boolean;
  document_type: {
    id: number;
    name: string;
    code: string;
    accepted_mimes: string;
    max_size_kb: number;
    description?: string | null;
  };
};

export function getToken(): string | null {
  if (typeof window === "undefined") return null;
  return localStorage.getItem("vas_token");
}

export function setToken(token: string) {
  localStorage.setItem("vas_token", token);
}

export function clearToken() {
  localStorage.removeItem("vas_token");
}

export function faydaLoginUrl(returnTo = "/auth/callback") {
  const redirect = encodeURIComponent(
    typeof window !== "undefined" ? `${window.location.origin}${returnTo}` : returnTo
  );
  return `${API}/auth/fayda/redirect?redirect=${redirect}`;
}

export async function api<T = unknown>(path: string, init: RequestInit = {}): Promise<T> {
  const headers = new Headers(init.headers);
  headers.set("Accept", "application/json");
  if (!(init.body instanceof FormData)) {
    headers.set("Content-Type", "application/json");
  }
  const t = getToken();
  if (t) headers.set("Authorization", `Bearer ${t}`);

  const res = await fetch(`${API}${path}`, { ...init, headers });
  if (res.status === 401) {
    clearToken();
  }
  if (!res.ok) {
    const body = await res.json().catch(() => ({}));
    const message =
      body.message ||
      (body.errors && Object.values(body.errors).flat().join(" ")) ||
      `Request failed (${res.status})`;
    throw new Error(message);
  }
  if (res.status === 204) return undefined as T;
  return res.json();
}

export const statusCopy: Record<
  Ticket["status"],
  { label: string; hint: string; tone: string }
> = {
  open: {
    label: "Submitted",
    hint: "Waiting for a supervisor to assign your request",
    tone: "tone-open",
  },
  in_progress: {
    label: "In progress",
    hint: "Our team is reviewing documents or approvals",
    tone: "tone-progress",
  },
  completed: {
    label: "Approved",
    hint: "Approval finished — closing shortly",
    tone: "tone-done",
  },
  closed: {
    label: "Closed",
    hint: "This request is complete",
    tone: "tone-closed",
  },
  rejected: {
    label: "Needs attention",
    hint: "Please update documents and we will re-check",
    tone: "tone-alert",
  },
};
