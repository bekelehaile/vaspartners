import type {
  Contact,
  DocumentRequirement,
  Service,
  Ticket,
} from "@/lib/api";

export const queryKeys = {
  contact: {
    me: ["contact", "me"] as const,
    tickets: ["contact", "tickets"] as const,
    ticketsFiltered: (filters: Record<string, unknown>) =>
      ["contact", "tickets", filters] as const,
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
  subscriptions: ["contact", "subscriptions"] as const,
  feedback: ["contact", "feedback"] as const,
  ticket: (publicId: string) => ["ticket", publicId] as const,
  ticketMessages: (publicId: string) => ["ticket", publicId, "messages"] as const,
  notifications: ["contact", "notifications"] as const,
};

export type { Contact, DocumentRequirement, Service, Ticket };
