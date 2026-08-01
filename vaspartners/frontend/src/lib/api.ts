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
    legal_name?: string | null;
    tin: string;
    phone?: string | null;
    otp_phone?: string | null;
    erca_phone?: string | null;
    revenue_phone?: string | null;
    email?: string | null;
    address?: string | null;
    member_count?: number;
    approval_status?: string | null;
    approval_note?: string | null;
    is_approved?: boolean;
    is_active?: boolean;
    tin_validated?: boolean;
    tin_format_valid?: boolean;
    erca_tin_verified?: boolean;
    erca_name_status?: string | null;
    erca_verified_at?: string | null;
    erca_last_checked_at?: string | null;
    needs_erca_name_consent?: boolean;
    needs_erca_name_entry?: boolean;
    erca_identity_locked?: boolean;
  } | null;
  memberships?: Array<{
    company_public_id?: string | null;
    company_name?: string | null;
    company_tin?: string | null;
    role?: string | null;
    is_active?: boolean;
    is_current?: boolean;
    is_approved?: boolean;
    company_is_active?: boolean;
    approval_status?: string | null;
    tin_validated?: boolean;
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
  /** True after at least one successful Fayda (National ID) sign-in; sticky across OTP logins. */
  fayda_verified?: boolean;
  /** True when verified via Fayda or CRM. */
  identity_verified?: boolean;
  identity_verified_via?: "fayda" | "crm" | null;
  identity_verified_at?: string | null;
  needs_identity_consent?: boolean;
  needs_manual_name?: boolean;
  identity_proposal?: IdentityConsentProposal | null;
};

export type IdentityConsentProposal = {
  source: "crm" | string;
  phone?: string | null;
  name?: string | null;
  email?: string | null;
  gender?: string | null;
  nationality?: string | null;
  birthdate?: string | null;
  identification_type?: string | null;
  identification_number?: string | null;
  /** @deprecated bill_complaint fields — no longer returned */
  customer_type?: string | null;
  primary_offer_name?: string | null;
  service_numbers?: string[];
};

export type IdentityAuthState = {
  needs_consent: boolean;
  needs_manual_name: boolean;
  needs_company?: boolean;
  crm_available: boolean;
  proposal: IdentityConsentProposal | null;
  verified_via?: string | null;
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
  status_label?: string;
  service_id?: number;
  service?: {
    id: number;
    name: string;
    slug: string;
    renewal_interval?: string | null;
    is_subscription_based?: boolean;
  };
  started_at?: string | null;
  current_period_start?: string | null;
  current_period_end?: string | null;
  next_renewal_due_at?: string | null;
  terminated_at?: string | null;
  renewal_interval?: string | null;
  renewal_interval_label?: string | null;
  company?: { id: number; name: string; tin?: string | null } | null;
  activated_by_contact?: {
    public_id?: string;
    name?: string;
    phone_number?: string | null;
  } | null;
  activated_by_ticket?: SubscriptionLinkedTicket | null;
  terminated_by_ticket?: SubscriptionLinkedTicket | null;
  tickets?: SubscriptionLinkedTicket[];
};

export type SubscriptionLinkedTicket = {
  tt_number: string;
  public_id?: string;
  status: string;
  requisition?: { id: number; name: string } | null;
  service?: { id: number; name: string } | null;
  created_at?: string | null;
};

export type PartnerRevenueRow = {
  id: number;
  period?: string | null;
  import_title?: string | null;
  service_id?: string | null;
  partner_name?: string | null;
  service_type?: string | null;
  /** Partner master-list phone (last 9). */
  phone?: string | null;
  /** Phone used / intended for revenue SMS. */
  sms_phone?: string | null;
  sms_phone_display?: string | null;
  amount?: number | null;
  amount_formatted?: string | null;
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

export type TicketStatusAuditEntry = {
  event: string;
  label: string;
  status?: string | null;
  status_label?: string | null;
  actor_name?: string | null;
  detail?: string | null;
  note?: string | null;
  at: string;
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
  /** Assigned account manager (name only) once the request is being handled. */
  assignee?: { name?: string | null } | null;
  service?: { id: number; name: string };
  requisition?: { id: number; name: string };
  created_at: string;
  updated_at?: string;
  status_audit?: TicketStatusAuditEntry[];
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
  attachment_status?: {
    state: "complete" | "incomplete" | "none_required" | string;
    label?: string;
    required_count?: number;
    uploaded_count?: number;
    missing_count?: number;
    missing_names?: string[];
  };
  can_delete?: boolean;
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

export type AuthConfig = {
  auth_mode: "fayda" | "phone_otp" | "both";
  fayda_enabled: boolean;
  phone_otp_enabled: boolean;
  note?: string | null;
  erca_tin?: {
    mode: "live" | "maintenance";
    available: boolean;
    message?: string | null;
  };
  genders?: string[];
  nationalities?: string[];
  default_nationality?: string;
};

export async function fetchAuthConfig(): Promise<AuthConfig> {
  const res = await api<{ data: AuthConfig }>("/auth/config");
  return res.data;
}

export async function requestPortalOtp(phone: string) {
  return api<{
    message: string;
    data: { phone: string; expires_in: number };
  }>("/auth/otp/request", {
    method: "POST",
    body: JSON.stringify({ phone }),
  });
}

export async function verifyPortalOtp(payload: {
  phone: string;
  code: string;
  name?: string;
  email?: string;
  gender?: string;
  nationality?: string;
}) {
  return api<{
    message: string;
    data: {
      token: string;
      is_new: boolean;
      expires_in?: number;
      identity: IdentityAuthState;
      contact: Contact;
    };
  }>("/auth/otp/verify", {
    method: "POST",
    body: JSON.stringify(payload),
  });
}

export async function submitIdentityConsent(payload: {
  action: "accept" | "decline" | "refresh";
  name?: string;
}) {
  return api<{
    message: string;
    data: { identity: IdentityAuthState; contact: Contact };
  }>("/auth/identity/consent", {
    method: "POST",
    body: JSON.stringify(payload),
  });
}

export async function submitErcaNameConsent(payload: {
  action: "use_legal" | "keep_both" | "provide_name";
  company_name?: string;
}) {
  return api<{ message: string; data: Contact }>("/profile/company/tin/name-consent", {
    method: "POST",
    body: JSON.stringify(payload),
  });
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
  if (res.status === 401 || (res.status === 403 && path === "/auth/me")) {
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
