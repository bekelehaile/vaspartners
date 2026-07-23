"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useRouter } from "next/navigation";
import {
  BlogPost,
  Customer,
  DocumentRequirement,
  FaqItem,
  GalleryItem,
  Service,
  Subscription,
  Ticket,
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
      }>("/subscriptions?per_page=100");
      return {
        items: Array.isArray(res.data) ? res.data : [],
        pendingNewServiceIds: Array.isArray(res.pending_new_service_ids)
          ? res.pending_new_service_ids.map(Number)
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

export function useCompleteCompanyProfile() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (values: CompanyProfileValues) => {
      const res = await api<{ data: Customer }>("/profile/company", {
        method: "POST",
        body: JSON.stringify(values),
      });
      return res.data;
    },
    onSuccess: (customer) => {
      queryClient.setQueryData(queryKeys.customer.me, customer);
      void queryClient.invalidateQueries({ queryKey: queryKeys.customer.me });
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

export function useUploadTicketDocument(publicId: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({
      documentTypeId,
      file,
    }: {
      documentTypeId: number;
      file: File;
    }) => {
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
    },
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

export function usePostTicketComment(publicId: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (body: string) => {
      await api(`/tickets/${publicId}/comments`, {
        method: "POST",
        body: JSON.stringify({ body }),
      });
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: queryKeys.ticket(publicId) });
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
