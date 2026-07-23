import { z } from "zod";

export const ticketCreateSchema = z.object({
  intent: z.enum(["subscribe", "manage"]),
  service_id: z.string().min(1, "Select a service"),
  requisition_id: z.string().min(1, "Select a request type"),
  subscription_id: z.string(),
  description: z
    .string()
    .trim()
    .min(1, "Enter a description")
    .max(5000, "Description is too long"),
}).superRefine((value, ctx) => {
  // Subscription-based manage changes need a subscription; one-off services do not.
  if (value.intent === "manage" && !value.service_id) {
    ctx.addIssue({
      code: z.ZodIssueCode.custom,
      message: "Select a service",
      path: ["service_id"],
    });
  }
});

export type TicketCreateValues = z.infer<typeof ticketCreateSchema>;

export const commentSchema = z
  .object({
    body: z.string().max(5000, "Message is too long").optional().default(""),
    attachment: z.any().optional().nullable(),
  })
  .superRefine((value, ctx) => {
    const body = (value.body || "").trim();
    const file = value.attachment as File | null | undefined;
    if (!body && !file) {
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        message: "Enter a message or attach a small PDF",
        path: ["body"],
      });
    }
    if (file && file instanceof File) {
      const name = file.name.toLowerCase();
      if (!name.endsWith(".pdf") && file.type !== "application/pdf") {
        ctx.addIssue({
          code: z.ZodIssueCode.custom,
          message: "Only PDF files are allowed",
          path: ["attachment"],
        });
      }
    }
  });

export type CommentValues = z.infer<typeof commentSchema>;
