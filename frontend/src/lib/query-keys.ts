import type {
  Customer,
  DocumentRequirement,
  Service,
  Ticket,
} from "@/lib/api";

export const queryKeys = {
  customer: {
    me: ["customer", "me"] as const,
    tickets: ["customer", "tickets"] as const,
    ticketsFiltered: (filters: Record<string, unknown>) =>
      ["customer", "tickets", filters] as const,
  },
  catalog: {
    faqs: ["catalog", "faqs"] as const,
    services: ["catalog", "services"] as const,
    documentRequirements: (serviceId: string, requisitionId: string) =>
      ["catalog", "document-requirements", serviceId, requisitionId] as const,
  },
  ticket: (publicId: string) => ["ticket", publicId] as const,
  notifications: ["customer", "notifications"] as const,
};

export type { Customer, DocumentRequirement, Service, Ticket };
