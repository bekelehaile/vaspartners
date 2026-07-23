const API = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000/api/v1";

export type Customer = {
  public_id: string;
  name: string;
  phone_number?: string | null;
  email?: string | null;
  gender?: string | null;
  nationality?: string | null;
  birthdate?: string | null;
  company_id?: number | null;
  company_role?: string | null;
  company_name?: string | null;
  company_tin?: string | null;
  company_phone?: string | null;
  company_email?: string | null;
  company_address?: string | null;
  company?: {
    public_id: string;
    name: string;
    tin: string;
    phone?: string | null;
    email?: string | null;
    address?: string | null;
  } | null;
  pending_company_request?: {
    public_id: string;
    type: "attach" | "detach";
    status: string;
    customer_note?: string | null;
    company?: { public_id: string; name: string; tin: string } | null;
    created_at?: string | null;
    has_proposal?: boolean;
    has_letter?: boolean;
  } | null;
  profile_completed_at?: string | null;
  profile_completed?: boolean;
};

export type Service = {
  id: number;
  name: string;
  slug: string;
  description?: string | null;
  type?: string | null;
  is_subscription_based?: boolean;
  renewal_interval?: string | null;
  category?: { id: number; name: string; slug: string };
  requisitions?: {
    id: number;
    name: string;
    slug: string;
    code?: string;
    creates_subscription?: boolean;
    requires_active_subscription?: boolean;
    renews_subscription?: boolean;
    terminates_subscription?: boolean;
  }[];
};

export type Subscription = {
  id: number;
  public_id: string;
  status: string;
  service_id?: number;
  service?: { id: number; name: string; slug: string; renewal_interval?: string | null };
  current_period_end?: string | null;
  next_renewal_due_at?: string | null;
};

export type FaqItem = {
  id: number;
  question: string;
  answer: string;
  sort_order?: number;
};

export type BlogPost = {
  id: number;
  title: string;
  slug: string;
  excerpt?: string | null;
  body?: string;
  cover_image?: string | null;
  cover_image_url?: string | null;
  is_featured?: boolean;
  published_at?: string | null;
};

export type GalleryItem = {
  id: number;
  title: string;
  caption?: string | null;
  image?: string;
  image_url?: string | null;
  alt_text?: string | null;
  album?: string | null;
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
  messages?: TicketMessage[];
  chat_locked?: boolean;
  chat_attachment_max_kb?: number;
  documents_locked?: boolean;
  documents?: {
    id: number;
    document_type_id?: number;
    original_name: string;
    document_type?: { id?: number; name: string; accepted_mimes?: string; max_size_kb?: number };
  }[];
};

export type TicketMessage = {
  id: number;
  body?: string | null;
  author_role: "staff" | "customer";
  author_label: string;
  has_attachment: boolean;
  attachment_name?: string | null;
  attachment_size_bytes?: number | null;
  attachment_url?: string | null;
  created_at?: string | null;
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

export function faydaLoginUrl() {
  return `${API}/auth/fayda/redirect`;
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
