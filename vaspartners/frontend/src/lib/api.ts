const API = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000/api/v1";

export type Contact = {
  public_id: string;
  name: string;
  phone_number?: string | null;
  email?: string | null;
  gender?: string | null;
  nationality?: string | null;
  birthdate?: string | null;
  identification_type?: string | null;
  identification_number?: string | null;
  company_id?: number | null;
  current_company_id?: number | null;
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
    member_count?: number;
    approval_status?: string | null;
    approval_note?: string | null;
    is_approved?: boolean;
  } | null;
  memberships?: Array<{
    company_public_id?: string | null;
    company_name?: string | null;
    company_tin?: string | null;
    role?: string | null;
    is_active?: boolean;
    is_current?: boolean;
    is_approved?: boolean;
    approval_status?: string | null;
  }>;
  company_can_detach?: boolean;
  company_needs_ownership_transfer?: boolean;
  company_membership_active?: boolean | null;
  company_can_edit?: boolean;
  company_can_manage_members?: boolean;
  company_permissions?: string[];
  company_permission_catalog?: Array<{
    key: string;
    label: string;
    description: string;
  }>;
  pending_membership_requests_count?: number;
  pending_company_request?: {
    public_id: string;
    type: "attach" | "detach" | "transfer_ownership";
    status: string;
    contact_note?: string | null;
    company?: {
      public_id: string;
      name: string;
      tin: string;
    } | null;
    target_contact?: {
      public_id: string;
      name: string;
    } | null;
    created_at?: string | null;
    has_proposal?: boolean;
    has_letter?: boolean;
  } | null;
  profile_completed_at?: string | null;
  profile_completed?: boolean;
};

export type ServiceGroup = {
  id: number;
  name: string;
  slug: string;
  key?: string;
};

export type Service = {
  id: number;
  name: string;
  slug: string;
  description?: string | null;
  type?: string | null;
  is_subscription_based?: boolean;
  renewal_interval?: string | null;
  category_id?: number;
  category?: ServiceGroup;
  categories?: ServiceGroup[];
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
  started_at?: string | null;
  current_period_start?: string | null;
  current_period_end?: string | null;
  next_renewal_due_at?: string | null;
  terminated_at?: string | null;
};

export type PartnerRevenueRow = {
  id: number;
  period?: string | null;
  import_title?: string | null;
  service_id?: string | null;
  partner_name?: string | null;
  service_type?: string | null;
  amount?: number | null;
  amount_formatted?: string | null;
  sheet_name?: string | null;
  imported_at?: string | null;
  sent_at?: string | null;
  sms_status: "not_sent" | "pending" | "sent" | "failed" | "skipped" | string;
  sms_error?: string | null;
  sms_sent_at?: string | null;
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
  contact?: { id?: number; public_id?: string; name?: string | null } | null;
  service?: { id: number; name: string };
  requisition?: { id: number; name: string };
  created_at: string;
  updated_at?: string;
  messages?: TicketMessage[];
  messages_meta?: {
    total: number;
    has_more_older: boolean;
    has_more_newer: boolean;
    oldest_id?: number | null;
    newest_id?: number | null;
  };
  chat_locked?: boolean;
  chat_attachment_max_kb?: number;
  documents_locked?: boolean;
  contact_can_edit?: boolean;
  documents?: {
    id: number;
    document_type_id?: number;
    original_name: string;
    download_url?: string | null;
    document_type?: { id?: number; name: string; accepted_mimes?: string; max_size_kb?: number };
  }[];
};

export type TicketMessage = {
  id: number;
  body?: string | null;
  author_role: "staff" | "contact";
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
  if (typeof window === "undefined") return;
  localStorage.removeItem("vas_token");
}

/** Drop portal auth + any client-side session leftovers (token, storage). */
export function clearClientSession() {
  if (typeof window === "undefined") return;
  clearToken();
  try {
    sessionStorage.clear();
  } catch {
    /* ignore */
  }
  try {
    const keys: string[] = [];
    for (let i = 0; i < localStorage.length; i++) {
      const key = localStorage.key(i);
      if (key && (key.startsWith("vas_") || key.startsWith("REACT_QUERY"))) {
        keys.push(key);
      }
    }
    keys.forEach((key) => localStorage.removeItem(key));
  } catch {
    /* ignore */
  }
}

/** @deprecated Prefer clearClientSession */
export const clearClientAuth = clearClientSession;

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

  const res = await fetch(`${API}${path}`, { ...init, headers, cache: "no-store" });
  if (res.status === 401) {
    clearClientSession();
    if (typeof window !== "undefined") {
      window.dispatchEvent(new Event("vas:unauthorized"));
    }
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
    label: "Pending",
    hint: "Waiting for Ethio telecom to assign and review your request",
    tone: "tone-open",
  },
  in_progress: {
    label: "In progress",
    hint: "Ethio telecom is handling this request. Documents are locked until it is sent back for updates.",
    tone: "tone-progress",
  },
  completed: {
    label: "Completed",
    hint: "Approval finished — this request is locked for changes.",
    tone: "tone-done",
  },
  closed: {
    label: "Closed",
    hint: "This request is complete and locked.",
    tone: "tone-closed",
  },
  rejected: {
    label: "Rejected",
    hint: "Update documents below, then wait for our team to re-check.",
    tone: "tone-alert",
  },
};

export type PartnerFeedback = {
  public_id: string;
  year: number;
  quarter: number;
  label: string;
  rating: number;
  description: string;
  company?: { public_id: string; name: string; tin?: string | null } | null;
  submitted_at?: string | null;
  created_at?: string | null;
};

export type FeedbackInbox = {
  current: {
    year: number;
    quarter: number;
    label: string;
    feedback: PartnerFeedback | null;
    can_submit: boolean;
  };
  items: PartnerFeedback[];
};
