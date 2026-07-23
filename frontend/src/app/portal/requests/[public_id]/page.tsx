"use client";

import Link from "next/link";
import { useForm } from "@tanstack/react-form";
import { useParams } from "next/navigation";
import { JourneyLaunchActions, PortalPageHeader } from "@/components/PortalPageHeader";
import { StatusJourney } from "@/components/StatusJourney";
import { TicketDocumentsPanel } from "@/components/TicketDocumentsPanel";
import { usePostTicketComment, useTicket } from "@/hooks/use-customer";
import { statusCopy } from "@/lib/api";
import { commentSchema } from "@/lib/schemas/ticket";

function fieldError(errors: unknown): string | null {
  if (!errors || !Array.isArray(errors) || errors.length === 0) return null;
  const first = errors[0];
  if (typeof first === "string") return first;
  if (first && typeof first === "object" && "message" in first) {
    return String((first as { message: unknown }).message);
  }
  return String(first);
}

export default function RequestDetailPage() {
  const params = useParams<{ public_id: string }>();
  const { data: ticket, isLoading, isError, error } = useTicket(params.public_id);
  const postComment = usePostTicketComment(params.public_id);

  const form = useForm({
    defaultValues: { body: "" },
    validators: {
      onSubmit: commentSchema,
    },
    onSubmit: async ({ value, formApi }) => {
      const parsed = commentSchema.parse(value);
      await postComment.mutateAsync(parsed.body);
      formApi.reset();
    },
  });

  return (
    <>
      <PortalPageHeader
        kicker={
          <Link href="/portal" className="linkish">
            ← My requests
          </Link>
        }
        title={ticket?.tt_number || "Request"}
        description={`${ticket?.service?.name || "Service"}${
          ticket?.requisition?.name ? ` · ${ticket.requisition.name}` : ""
        }`}
        actions={<JourneyLaunchActions />}
      />

      <div className="section section-flush">
        {(isError || postComment.isError) && (
          <div className="alert">
            {postComment.isError
              ? postComment.error instanceof Error
                ? postComment.error.message
                : "Could not post comment"
              : error instanceof Error
                ? error.message
                : "Unable to load request"}
          </div>
        )}

        {isLoading || !ticket ? (
          <div className="panel">
            <div className="empty">Loading request…</div>
          </div>
        ) : (
          <div className="portal-grid">
            <section className="panel">
              <h2>Progress</h2>
              <StatusJourney status={ticket.status} />
              <p className="muted" style={{ marginTop: "1rem" }}>
                {statusCopy[ticket.status]?.hint}
              </p>
              {ticket.description && (
                <>
                  <h2 style={{ marginTop: "1.5rem" }}>Description</h2>
                  <p className="muted">{ticket.description}</p>
                </>
              )}

              <h2 style={{ marginTop: "1.5rem" }}>Documents</h2>
              <TicketDocumentsPanel
                ticket={ticket}
                mode="manage"
                serviceId={ticket.service?.id ? String(ticket.service.id) : undefined}
                requisitionId={
                  ticket.requisition?.id ? String(ticket.requisition.id) : undefined
                }
              />
            </section>

            <aside className="panel">
              <h2>Activity</h2>
              <ol style={{ margin: 0, paddingLeft: "1.1rem", display: "grid", gap: "0.65rem" }}>
                {(ticket.status_histories || []).map((h, i) => (
                  <li key={`${h.created_at}-${i}`} className="muted">
                    <strong style={{ color: "var(--et-ink)" }}>{h.to_status}</strong>
                    {h.note ? ` — ${h.note}` : ""}
                    <div style={{ fontSize: "0.85rem" }}>
                      {new Date(h.created_at).toLocaleString()}
                    </div>
                  </li>
                ))}
              </ol>

              <form
                onSubmit={(e) => {
                  e.preventDefault();
                  e.stopPropagation();
                  void form.handleSubmit();
                }}
                style={{ marginTop: "1.25rem" }}
                noValidate
              >
                <form.Field name="body">
                  {(field) => {
                    const err =
                      field.state.meta.isTouched || form.state.submissionAttempts > 0
                        ? fieldError(field.state.meta.errors)
                        : null;
                    return (
                      <div className={`field${err ? " has-error" : ""}`}>
                        <label htmlFor={field.name}>Add a comment</label>
                        <textarea
                          id={field.name}
                          name={field.name}
                          rows={3}
                          value={field.state.value}
                          onBlur={field.handleBlur}
                          onChange={(e) => field.handleChange(e.target.value)}
                        />
                        {err && <p className="field-error">{err}</p>}
                      </div>
                    );
                  }}
                </form.Field>
                <form.Subscribe selector={(s) => s.isSubmitting}>
                  {(isSubmitting) => (
                    <button
                      className="btn-primary"
                      disabled={isSubmitting || postComment.isPending}
                    >
                      {isSubmitting || postComment.isPending ? "Sending…" : "Send"}
                    </button>
                  )}
                </form.Subscribe>
              </form>
            </aside>
          </div>
        )}
      </div>
    </>
  );
}
