"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useRouter } from "next/navigation";
import {
  Customer,
  DocumentRequirement,
  Service,
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
      const res = await api<{ data: { id: number; question: string; answer: string }[] }>(
        "/faqs"
      );
      return Array.isArray(res.data) ? res.data.slice(0, 12) : [];
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

export function useTickets(options?: { enabled?: boolean }) {
  const enabled = options?.enabled ?? true;
  return useQuery({
    queryKey: queryKeys.customer.tickets,
    enabled: enabled && !!getToken(),
    queryFn: async () => {
      const res = await api<{ data: Ticket[] }>("/tickets");
      return Array.isArray(res.data) ? res.data : [];
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
      const res = await api<{ data: Ticket }>("/tickets", {
        method: "POST",
        body: JSON.stringify({
          service_id: Number(values.service_id),
          requisition_id: Number(values.requisition_id),
          description: values.description || null,
          building: values.building || null,
          location: values.location || null,
        }),
      });
      return res.data;
    },
    onSuccess: (ticket) => {
      queryClient.setQueryData(queryKeys.ticket(ticket.public_id), ticket);
      void queryClient.invalidateQueries({ queryKey: queryKeys.customer.tickets });
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
      await api(`/tickets/${publicId}/documents`, { method: "POST", body });
      return { documentTypeId, fileName: file.name };
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
