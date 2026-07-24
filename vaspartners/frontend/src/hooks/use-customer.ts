"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useRouter } from "next/navigation";
import { useState } from "react";
import {
  BlogPost,
  Customer,
  DocumentRequirement,
  FaqItem,
  GalleryItem,
  Service,
  Subscription,
  Ticket,
  TicketMessage,
  api,
  clearToken,
  getToken,
} from "@/lib/api";
import { queryKeys } from "@/lib/query-keys";
import type { CompanyProfileValues } from "@/lib/schemas/company";
import type { TicketCreateValues } from "@/lib/schemas/ticket";

export { queryKeys };

export function useCustomer(options?: { enabled?: boolean }) {
  const enabled = options?.enabled ?? true;
  return useQuery({
    queryKey: queryKeys.customer.me,
    enabled: enabled && !!getToken(),
    queryFn: async () => {
      const res = await api<{ data: Customer }>("/auth/me");
      return res.data;
    },
  });
}

export function useFaqs() {
  return useQuery({
    queryKey: queryKeys.catalog.faqs,
    queryFn: async () => {
      const res = await api<{ data: FaqItem[] }>("/faqs");
      return Array.isArray(res.data) ? res.data : [];
    },
  });
}

export function useBlogPosts() {
  return useQuery({
    queryKey: queryKeys.catalog.blogPosts,
    queryFn: async () => {
      const res = await api<{ data: BlogPost[] }>("/blog-posts");
      return Array.isArray(res.data) ? res.data : [];
    },
  });
}

export function useBlogPost(slug: string) {
  return useQuery({
    queryKey: queryKeys.catalog.blogPost(slug),
    enabled: !!slug,
    queryFn: async () => {
      const res = await api<{ data: BlogPost }>(`/blog-posts/${encodeURIComponent(slug)}`);
      return res.data;
    },
  });
}

export function useGallery() {
  return useQuery({
    queryKey: queryKeys.catalog.gallery,
    queryFn: async () => {
      const res = await api<{ data: GalleryItem[] }>("/gallery");
      return Array.isArray(res.data) ? res.data : [];
    },
  });
}

export function useServices() {
  return useQuery({
    queryKey: queryKeys.catalog.services,
    queryFn: async () => {
      const res = await api<{ data: Service[] }>("/services");
      return res.data ?? [];
    },
  });
}

export function useSubscriptions(options?: { enabled?: boolean }) {
  const enabled = options?.enabled ?? true;
  return useQuery({
    queryKey: queryKeys.subscriptions,
    enabled: enabled && !!getToken(),
    queryFn: async () => {
      const res = await api<{
        data: Subscription[];
        pending_new_service_ids?: number[];
        pending_requests?: {
          service_id: number;
          requisition_id: number;
          tt_number: string;
          public_id: string;
          status: string;
        }[];
      }>("/subscriptions?per_page=100");
      return {
        items: Array.isArray(res.data) ? res.data : [],
        pendingNewServiceIds: Array.isArray(res.pending_new_service_ids)
          ? res.pending_new_service_ids.map(Number)
          : [],
        pendingRequests: Array.isArray(res.pending_requests)
          ? res.pending_requests.map((r) => ({
              service_id: Number(r.service_id),
              requisition_id: Number(r.requisition_id),
              tt_number: r.tt_number,
              public_id: r.public_id,
              status: r.status,
            }))
          : [],
      };
    },
  });
}

export type TicketFilters = {
  status?: string;
  search?: string;
  service_id?: string;
  page?: number;
  per_page?: number;
};

export type TicketsPage = {
  items: Ticket[];
  total: number;
  currentPage: number;
  lastPage: number;
  perPage: number;
};

export function useTickets(
  filters: TicketFilters = {},
  options?: { enabled?: boolean }
) {
  const enabled = options?.enabled ?? true;
  const normalized = {
    status: filters.status || "",
    search: filters.search || "",
    service_id: filters.service_id || "",
    page: filters.page || 1,
    per_page: filters.per_page || 15,
  };

  return useQuery({
    queryKey: queryKeys.customer.ticketsFiltered(normalized),
    enabled: enabled && !!getToken(),
    queryFn: async (): Promise<TicketsPage> => {
      const qs = new URLSearchParams();
      if (normalized.status) qs.set("status", normalized.status);
      if (normalized.search) qs.set("search", normalized.search);
      if (normalized.service_id) qs.set("service_id", normalized.service_id);
      qs.set("page", String(normalized.page));
      qs.set("per_page", String(normalized.per_page));

      const res = await api<{
        data: Ticket[];
        total: number;
        current_page: number;
        last_page: number;
        per_page: number;
      }>(`/tickets?${qs.toString()}`);

      return {
        items: Array.isArray(res.data) ? res.data : [],
        total: res.total ?? 0,
        currentPage: res.current_page ?? 1,
        lastPage: res.last_page ?? 1,
        perPage: res.per_page ?? normalized.per_page,
      };
    },
  });
}

export function useTicket(publicId: string) {
  return useQuery({
    queryKey: queryKeys.ticket(publicId),
    enabled: !!publicId && !!getToken(),
    queryFn: async () => {
      const res = await api<{ data: Ticket }>(`/tickets/${publicId}`);
      return res.data;
    },
  });
}

export function useDocumentRequirements(serviceId: string, requisitionId: string) {
  return useQuery({
    queryKey: queryKeys.catalog.documentRequirements(serviceId, requisitionId),
    enabled: !!serviceId && !!requisitionId,
    queryFn: async () => {
      const res = await api<{ data: DocumentRequirement[] }>(
        `/document-requirements?service_id=${serviceId}&requisition_id=${requisitionId}`
      );
      return res.data ?? [];
    },
  });
}

export function useSwitchCompany() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (company_public_id: string) => {
      const res = await api<{ data: Customer }>("/profile/company/switch", {
        method: "POST",
        body: JSON.stringify({ company_public_id }),
      });
      return res.data;
    },
    onSuccess: (customer) => {
      queryClient.setQueryData(queryKeys.customer.me, customer);
      void queryClient.invalidateQueries({ queryKey: queryKeys.customer.me });
      void queryClient.invalidateQueries({ queryKey: queryKeys.subscriptions });
      void queryClient.invalidateQueries({ queryKey: queryKeys.customer.tickets });
      void queryClient.invalidateQueries({ queryKey: ["company-members"] });
      void queryClient.invalidateQueries({ queryKey: ["company-membership-requests"] });
      void queryClient.invalidateQueries({ queryKey: ["company-requests-inbox"] });
    },
  });
}

export function useCompleteCompanyProfile() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (
      values: CompanyProfileValues & { create_new?: boolean },
    ) => {
      const res = await api<{ data: Customer }>("/profile/company", {
        method: "POST",
        body: JSON.stringify(values),
      });
      return res.data;
    },
    onSuccess: (customer) => {
      queryClient.setQueryData(queryKeys.customer.me, customer);
      void queryClient.invalidateQueries({ queryKey: queryKeys.customer.me });
      void queryClient.invalidateQueries({ queryKey: ["company-requests-inbox"] });
    },
  });
}

export function useLookupCompany(tin: string, licenseNumber: string) {
  return useQuery({
    queryKey: ["company-lookup", tin, licenseNumber],
    enabled: tin.trim().length >= 5 && licenseNumber.trim().length >= 3,
    queryFn: async () => {
      const params = new URLSearchParams({
        tin: tin.trim(),
        license_number: licenseNumber.trim(),
      });
      const res = await api<{
        data: { public_id: string; name: string; tin: string; license_number: string };
      }>(`/profile/company/lookup?${params.toString()}`);
      return res.data;
    },
    retry: false,
  });
}

export function useAttachCompany() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (payload: {
      company_tin: string;
      company_license_number: string;
      note?: string;
    }) => {
      const res = await api<{ data: Customer }>("/profile/company/attach", {
        method: "POST",
        body: JSON.stringify(payload),
      });
      return res.data;
    },
    onSuccess: (customer) => {
      queryClient.setQueryData(queryKeys.customer.me, customer);
      void queryClient.invalidateQueries({ queryKey: queryKeys.customer.me });
      void queryClient.invalidateQueries({ queryKey: ["company-requests-inbox"] });
    },
  });
}

export type MembershipRequest = {
  public_id: string;
  type: string;
  status: string;
  customer_note?: string | null;
  created_at?: string | null;
  applicant?: {
    public_id?: string | null;
    name?: string | null;
    phone_number?: string | null;
    email?: string | null;
  };
};

export type CompanyRequestCard = {
  kind: "membership_change" | "company_profile";
  public_id: string;
  type: string;
  status: string;
  direction?: "submitted" | "to_review";
  awaiting?: string | null;
  customer_note?: string | null;
  decision_note?: string | null;
  decided_by?: string | null;
  created_at?: string | null;
  reviewed_at?: string | null;
  can_approve?: boolean;
  can_reject?: boolean;
  can_cancel?: boolean;
  company?: {
    public_id?: string | null;
    name?: string | null;
    tin?: string | null;
    license_number?: string | null;
  } | null;
  applicant?: {
    public_id?: string | null;
    name?: string | null;
    phone_number?: string | null;
    email?: string | null;
  } | null;
  target_customer?: {
    public_id?: string | null;
    name?: string | null;
  } | null;
};

export type CompanyRequestsInboxData = {
  submitted: CompanyRequestCard[];
  to_review: CompanyRequestCard[];
  summary: {
    submitted_pending: number;
    to_review_pending: number;
  };
};

export function useCompanyRequestsInbox(enabled: boolean) {
  return useQuery({
    queryKey: ["company-requests-inbox"],
    enabled,
    queryFn: async () => {
      const res = await api<{ data: CompanyRequestsInboxData }>(
        "/profile/company/requests",
      );
      return res.data;
    },
  });
}

export function useCancelCompanyRequest() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (publicId: string) => {
      const res = await api<{ data: Customer }>(
        `/profile/company/requests/${publicId}/cancel`,
        { method: "POST", body: "{}" },
      );
      return res.data;
    },
    onSuccess: (customer) => {
      queryClient.setQueryData(queryKeys.customer.me, customer);
      void queryClient.invalidateQueries({ queryKey: queryKeys.customer.me });
      void queryClient.invalidateQueries({ queryKey: ["company-requests-inbox"] });
      void queryClient.invalidateQueries({ queryKey: ["company-membership-requests"] });
    },
  });
}

export function useMembershipRequests(enabled: boolean) {
  return useQuery({
    queryKey: ["company-membership-requests"],
    enabled,
    queryFn: async () => {
      const res = await api<{ data: MembershipRequest[] }>(
        "/profile/company/membership-requests",
      );
      return res.data;
    },
  });
}

export function useDecideMembershipRequest() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (payload: {
      public_id: string;
      decision: "approve" | "reject";
      note?: string;
    }) => {
      const res = await api<{ data: Customer }>(
        `/profile/company/membership-requests/${payload.public_id}/${payload.decision}`,
        {
          method: "POST",
          body: JSON.stringify({ note: payload.note || undefined }),
        },
      );
      return res.data;
    },
    onSuccess: (customer) => {
      queryClient.setQueryData(queryKeys.customer.me, customer);
      void queryClient.invalidateQueries({ queryKey: queryKeys.customer.me });
      void queryClient.invalidateQueries({ queryKey: ["company-membership-requests"] });
      void queryClient.invalidateQueries({ queryKey: ["company-requests-inbox"] });
    },
  });
}

export function useDetachCompany() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (payload?: { note?: string }) => {
      const res = await api<{ data: Customer }>("/profile/company/detach", {
        method: "POST",
        body: JSON.stringify({ note: payload?.note?.trim() || undefined }),
      });
      return res.data;
    },
    onSuccess: (customer) => {
      queryClient.setQueryData(queryKeys.customer.me, customer);
      void queryClient.invalidateQueries({ queryKey: queryKeys.customer.me });
      void queryClient.invalidateQueries({ queryKey: queryKeys.subscriptions });
      void queryClient.invalidateQueries({ queryKey: ["company-membership-requests"] });
      void queryClient.invalidateQueries({ queryKey: ["company-requests-inbox"] });
    },
  });
}

export type CompanyMemberOption = {
  public_id?: string | null;
  name?: string | null;
  phone_number?: string | null;
  email?: string | null;
  gender?: string | null;
  nationality?: string | null;
  birthdate?: string | null;
  identification_type?: string | null;
  identification_number?: string | null;
  role?: string | null;
  is_active?: boolean;
  is_owner?: boolean;
};

export function useCompanyMembers(enabled: boolean) {
  return useQuery({
    queryKey: ["company-members"],
    enabled,
    queryFn: async () => {
      const res = await api<{ data: CompanyMemberOption[] }>("/profile/company/members");
      return res.data;
    },
  });
}

export function useTransferOwnership() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (payload: {
      target_customer: string;
      letter: File;
      note?: string;
    }) => {
      const form = new FormData();
      form.append("target_customer", payload.target_customer);
      form.append("letter", payload.letter);
      if (payload.note?.trim()) {
        form.append("note", payload.note.trim());
      }
      const res = await api<{ data: Customer }>("/profile/company/transfer-ownership", {
        method: "POST",
        body: form,
      });
      return res.data;
    },
    onSuccess: (customer) => {
      queryClient.setQueryData(queryKeys.customer.me, customer);
      void queryClient.invalidateQueries({ queryKey: queryKeys.customer.me });
      void queryClient.invalidateQueries({ queryKey: ["company-members"] });
    },
  });
}

export function useCreateTicket() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (values: TicketCreateValues) => {
      const payload: Record<string, unknown> = {
        service_id: Number(values.service_id),
        requisition_id: Number(values.requisition_id),
        description: values.description.trim(),
      };
      if (values.subscription_id) {
        payload.subscription_id = Number(values.subscription_id);
      }

      const res = await api<{ data: Ticket }>("/tickets", {
        method: "POST",
        body: JSON.stringify(payload),
      });
      return res.data;
    },
    onSuccess: (ticket) => {
      queryClient.setQueryData(queryKeys.ticket(ticket.public_id), ticket);
      void queryClient.invalidateQueries({ queryKey: queryKeys.customer.tickets });
      void queryClient.invalidateQueries({ queryKey: queryKeys.subscriptions });
    },
  });
}

export async function uploadTicketDocumentFile(
  publicId: string,
  documentTypeId: number,
  file: File
): Promise<{ documentId: number; documentTypeId: number; fileName: string }> {
  const body = new FormData();
  body.append("document_type_id", String(documentTypeId));
  body.append("file", file);
  const res = await api<{
    data: { id: number; original_name: string; document_type_id: number };
  }>(`/tickets/${publicId}/documents`, { method: "POST", body });
  return {
    documentId: res.data.id,
    documentTypeId: res.data.document_type_id ?? documentTypeId,
    fileName: res.data.original_name || file.name,
  };
}

export function useUploadTicketDocument(publicId: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({
      documentTypeId,
      file,
    }: {
      documentTypeId: number;
      file: File;
    }) => uploadTicketDocumentFile(publicId, documentTypeId, file),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: queryKeys.ticket(publicId) });
      void queryClient.invalidateQueries({ queryKey: queryKeys.customer.tickets });
    },
  });
}

export function useDeleteTicketDocument(publicId: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (documentId: number) => {
      await api(`/tickets/${publicId}/documents/${documentId}`, { method: "DELETE" });
      return documentId;
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: queryKeys.ticket(publicId) });
      void queryClient.invalidateQueries({ queryKey: queryKeys.customer.tickets });
    },
  });
}

export function useTicketMessages(
  publicId: string,
  options?: {
    initialMessages?: TicketMessage[];
    initialHasMoreOlder?: boolean;
    initialTotal?: number;
    pollMs?: number;
  },
) {
  const queryClient = useQueryClient();
  const [loadingOlder, setLoadingOlder] = useState(false);
  const [error, setError] = useState<Error | null>(null);

  const query = useQuery({
    queryKey: queryKeys.ticketMessages(publicId),
    enabled: !!publicId && !!getToken(),
    initialData: options?.initialMessages?.length
      ? {
          messages: options.initialMessages,
          meta: {
            total: options.initialTotal ?? options.initialMessages.length,
            has_more_older: options.initialHasMoreOlder ?? false,
            has_more_newer: false,
            oldest_id: options.initialMessages[0]?.id ?? null,
            newest_id: options.initialMessages[options.initialMessages.length - 1]?.id ?? null,
          },
        }
      : undefined,
    refetchInterval: options?.pollMs ?? 8000,
    queryFn: async () => {
      const current = queryClient.getQueryData<{
        messages: TicketMessage[];
        meta: {
          total: number;
          has_more_older: boolean;
          has_more_newer: boolean;
          oldest_id: number | null;
          newest_id: number | null;
        };
      }>(queryKeys.ticketMessages(publicId));

      const newestId = current?.meta?.newest_id ?? current?.messages?.[current.messages.length - 1]?.id;
      if (newestId) {
        const res = await api<{
          data: TicketMessage[];
          meta: {
            total: number;
            has_more_older: boolean;
            has_more_newer: boolean;
            oldest_id: number | null;
            newest_id: number | null;
          };
        }>(`/tickets/${publicId}/messages?after_id=${newestId}&limit=50`);

        const existing = current?.messages ?? [];
        const byId = new Map<number, TicketMessage>();
        for (const m of existing) byId.set(m.id, m);
        for (const m of res.data ?? []) byId.set(m.id, m);
        const merged = [...byId.values()].sort((a, b) => a.id - b.id);

        return {
          messages: merged,
          meta: {
            total: res.meta?.total ?? merged.length,
            has_more_older: current?.meta?.has_more_older ?? false,
            has_more_newer: res.meta?.has_more_newer ?? false,
            oldest_id: merged[0]?.id ?? null,
            newest_id: merged[merged.length - 1]?.id ?? null,
          },
        };
      }

      const res = await api<{
        data: TicketMessage[];
        meta: {
          total: number;
          has_more_older: boolean;
          has_more_newer: boolean;
          oldest_id: number | null;
          newest_id: number | null;
        };
      }>(`/tickets/${publicId}/messages?limit=40`);

      return {
        messages: res.data ?? [],
        meta: res.meta,
      };
    },
  });

  const loadOlder = async () => {
    const current = query.data;
    const oldestId = current?.meta?.oldest_id ?? current?.messages?.[0]?.id;
    if (!oldestId || loadingOlder) return;
    setLoadingOlder(true);
    setError(null);
    try {
      const res = await api<{
        data: TicketMessage[];
        meta: {
          total: number;
          has_more_older: boolean;
          has_more_newer: boolean;
          oldest_id: number | null;
          newest_id: number | null;
        };
      }>(`/tickets/${publicId}/messages?before_id=${oldestId}&limit=30`);

      const existing = current?.messages ?? [];
      const byId = new Map<number, TicketMessage>();
      for (const m of res.data ?? []) byId.set(m.id, m);
      for (const m of existing) byId.set(m.id, m);
      const merged = [...byId.values()].sort((a, b) => a.id - b.id);

      queryClient.setQueryData(queryKeys.ticketMessages(publicId), {
        messages: merged,
        meta: {
          total: res.meta?.total ?? merged.length,
          has_more_older: res.meta?.has_more_older ?? false,
          has_more_newer: current?.meta?.has_more_newer ?? false,
          oldest_id: merged[0]?.id ?? null,
          newest_id: merged[merged.length - 1]?.id ?? null,
        },
      });
    } catch (e) {
      setError(e instanceof Error ? e : new Error("Could not load earlier messages"));
    } finally {
      setLoadingOlder(false);
    }
  };

  return {
    messages: query.data?.messages ?? options?.initialMessages ?? [],
    total: query.data?.meta?.total ?? options?.initialMessages?.length ?? 0,
    hasMoreOlder: query.data?.meta?.has_more_older ?? false,
    loadingOlder,
    isLoading: query.isLoading,
    error: error || (query.error instanceof Error ? query.error : null),
    loadOlder,
    refetch: query.refetch,
  };
}

export function usePostTicketComment(publicId: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (payload: { body?: string; attachment?: File | null }) => {
      const form = new FormData();
      const body = (payload.body || "").trim();
      if (body) form.append("body", body);
      if (payload.attachment) form.append("attachment", payload.attachment);

      const res = await api<{ data: TicketMessage }>(`/tickets/${publicId}/comments`, {
        method: "POST",
        body: form,
      });
      return res.data;
    },
    onSuccess: (message) => {
      queryClient.setQueryData(
        queryKeys.ticketMessages(publicId),
        (prev:
          | {
              messages: TicketMessage[];
              meta: {
                total: number;
                has_more_older: boolean;
                has_more_newer: boolean;
                oldest_id: number | null;
                newest_id: number | null;
              };
            }
          | undefined) => {
          const existing = prev?.messages ?? [];
          if (existing.some((m) => m.id === message.id)) {
            return prev;
          }
          const merged = [...existing, message].sort((a, b) => a.id - b.id);
          return {
            messages: merged,
            meta: {
              total: (prev?.meta?.total ?? existing.length) + 1,
              has_more_older: prev?.meta?.has_more_older ?? false,
              has_more_newer: false,
              oldest_id: merged[0]?.id ?? null,
              newest_id: merged[merged.length - 1]?.id ?? null,
            },
          };
        },
      );
      void queryClient.invalidateQueries({ queryKey: queryKeys.notifications });
    },
  });
}

export type AppNotification = {
  id: string;
  title: string;
  body: string;
  template?: string | null;
  ticket_public_id?: string | null;
  tt_number?: string | null;
  url: string;
  read_at?: string | null;
  created_at?: string | null;
};

export function useNotifications(options?: { enabled?: boolean; refetchInterval?: number }) {
  const enabled = options?.enabled ?? true;
  return useQuery({
    queryKey: queryKeys.notifications,
    enabled: enabled && !!getToken(),
    refetchInterval: options?.refetchInterval ?? 30_000,
    queryFn: async () => {
      const res = await api<{ data: AppNotification[]; unread_count: number }>("/notifications");
      return {
        items: Array.isArray(res.data) ? res.data : [],
        unreadCount: res.unread_count ?? 0,
      };
    },
  });
}

export function useMarkNotificationRead() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (id: string) => {
      await api(`/notifications/${id}/read`, { method: "POST" });
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: queryKeys.notifications });
    },
  });
}

export function useMarkAllNotificationsRead() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async () => {
      await api("/notifications/read-all", { method: "POST" });
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: queryKeys.notifications });
    },
  });
}

export function useLogout() {
  const router = useRouter();
  const queryClient = useQueryClient();

  return async () => {
    try {
      await api("/auth/logout", { method: "POST" });
    } catch {
      /* ignore */
    }
    clearToken();
    queryClient.clear();
    router.replace("/");
  };
}
