import { z } from "zod";

export const ticketCreateSchema = z.object({
  service_id: z.string().min(1, "Select a service"),
  requisition_id: z.string().min(1, "Select a request type"),
  description: z
    .string()
    .trim()
    .min(1, "Enter a description")
    .max(5000, "Description is too long"),
});

export type TicketCreateValues = z.infer<typeof ticketCreateSchema>;

export const commentSchema = z.object({
  body: z
    .string()
    .trim()
    .min(1, "Enter a comment")
    .max(5000, "Comment is too long"),
});

export type CommentValues = z.infer<typeof commentSchema>;
