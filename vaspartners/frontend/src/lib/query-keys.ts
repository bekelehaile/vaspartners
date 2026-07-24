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
    blogPosts: ["catalog", "blog-posts"] as const,
    blogPost: (slug: string) => ["catalog", "blog-posts", slug] as const,
    gallery: ["catalog", "gallery"] as const,
    documentRequirements: (serviceId: string, requisitionId: string) =>
      ["catalog", "document-requirements", serviceId, requisitionId] as const,
  },
  subscriptions: ["customer", "subscriptions"] as const,
  ticket: (publicId: string) => ["ticket", publicId] as const,
  ticketMessages: (publicId: string) => ["ticket", publicId, "messages"] as const,
  notifications: ["customer", "notifications"] as const,
};

export type { Customer, DocumentRequirement, Service, Ticket };
