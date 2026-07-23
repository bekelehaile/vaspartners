"use client";

import Link from "next/link";
import { useMemo, useState } from "react";
import { useForm } from "@tanstack/react-form";
import { useParams } from "next/navigation";
import { JourneyLaunchActions, PortalPageHeader } from "@/components/PortalPageHeader";
import { StatusJourney } from "@/components/StatusJourney";
import { TicketDocumentsPanel } from "@/components/TicketDocumentsPanel";
import { usePostTicketComment, useTicket } from "@/hooks/use-customer";
import { getToken, statusCopy, type TicketMessage } from "@/lib/api";
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

function formatBytes(bytes?: number | null): string {
  if (!bytes || bytes < 1) return "";
  if (bytes < 1024) return `${bytes} B`;
  return `${(bytes / 1024).toFixed(1)} KB`;
}

async function downloadAttachment(message: TicketMessage) {
  if (!message.attachment_url) return;
  const token = getToken();
  const res = await fetch(message.attachment_url, {
    headers: {
      Accept: "application/pdf",
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
  });
  if (!res.ok) throw new Error("Could not download attachment");
  const blob = await res.blob();
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = message.attachment_name || "attachment.pdf";
  a.click();
  URL.revokeObjectURL(url);
}

export default function RequestDetailPage() {
  const params = useParams<{ public_id: string }>();
  const { data: ticket, isLoading, isError, error } = useTicket(params.public_id);
  const postComment = usePostTicketComment(params.public_id);
  const [fileLabel, setFileLabel] = useState<string | null>(null);
  const [downloadError, setDownloadError] = useState<string | null>(null);

  const maxKb = ticket?.chat_attachment_max_kb ?? 2048;
  const chatLocked = !!ticket?.chat_locked;
  const messages = useMemo(
    () => [...(ticket?.messages || [])].sort((a, b) => {
      const at = a.created_at ? new Date(a.created_at).getTime() : 0;
      const bt = b.created_at ? new Date(b.created_at).getTime() : 0;
      return at - bt;
    }),
    [ticket?.messages],
  );

  const form = useForm({
    defaultValues: { body: "", attachment: null as File | null },
    validators: {
      onSubmit: commentSchema,
    },
    onSubmit: async ({ value, formApi }) => {
      const parsed = commentSchema.parse(value);
      const file = (parsed.attachment as File | null | undefined) || null;
      if (file && file.size > maxKb * 1024) {
        throw new Error(`PDF must be ${maxKb} KB or smaller`);
      }
      await postComment.mutateAsync({
        body: parsed.body,
        attachment: file,
      });
      formApi.reset();
      setFileLabel(null);
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
        {(isError || postComment.isError || downloadError) && (
          <div className="alert">
            {downloadError
              ? downloadError
              : postComment.isError
                ? postComment.error instanceof Error
                  ? postComment.error.message
                  : "Could not send message"
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

              <h2 style={{ marginTop: "1.5rem" }}>Activity</h2>
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
            </section>

            <aside className="panel chat-panel">
              <h2>Messages</h2>
              <p className="muted" style={{ marginTop: 0 }}>
                Chat with your account manager about missing documents or extra details. You can
                attach a small PDF (max {maxKb} KB).
              </p>

              <div className="chat-thread" role="log" aria-live="polite">
                {messages.length === 0 ? (
                  <div className="empty" style={{ padding: "1rem 0" }}>
                    No messages yet. Ask a question or wait for your account manager.
                  </div>
                ) : (
                  messages.map((m) => {
                    const mine = m.author_role === "customer";
                    return (
                      <div
                        key={m.id}
                        className={`chat-bubble ${mine ? "chat-bubble-mine" : "chat-bubble-staff"}`}
                      >
                        <div className="chat-meta">
                          <strong>{m.author_label}</strong>
                          {m.created_at && (
                            <span>{new Date(m.created_at).toLocaleString()}</span>
                          )}
                        </div>
                        {m.body && <p>{m.body}</p>}
                        {m.has_attachment && (
                          <button
                            type="button"
                            className="chat-attach"
                            onClick={() => {
                              setDownloadError(null);
                              void downloadAttachment(m).catch(() =>
                                setDownloadError("Could not download the PDF attachment"),
                              );
                            }}
                          >
                            PDF: {m.attachment_name || "attachment.pdf"}
                            {m.attachment_size_bytes
                              ? ` (${formatBytes(m.attachment_size_bytes)})`
                              : ""}
                          </button>
                        )}
                      </div>
                    );
                  })
                )}
              </div>

              {chatLocked ? (
                <p className="muted" style={{ marginTop: "1rem" }}>
                  Messaging is closed for this completed request.
                </p>
              ) : (
                <form
                  onSubmit={(e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    void form.handleSubmit();
                  }}
                  style={{ marginTop: "1rem" }}
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
                          <label htmlFor={field.name}>Your message</label>
                          <textarea
                            id={field.name}
                            name={field.name}
                            rows={3}
                            value={field.state.value}
                            onBlur={field.handleBlur}
                            onChange={(e) => field.handleChange(e.target.value)}
                            placeholder="Ask about missing docs or add more information…"
                          />
                          {err && <p className="field-error">{err}</p>}
                        </div>
                      );
                    }}
                  </form.Field>

                  <form.Field name="attachment">
                    {(field) => {
                      const err =
                        form.state.submissionAttempts > 0
                          ? fieldError(field.state.meta.errors)
                          : null;
                      return (
                        <div className={`field${err ? " has-error" : ""}`}>
                          <label htmlFor="chat-pdf">Attach small PDF (optional)</label>
                          <input
                            id="chat-pdf"
                            type="file"
                            accept="application/pdf,.pdf"
                            onChange={(e) => {
                              const file = e.target.files?.[0] || null;
                              field.handleChange(file);
                              setFileLabel(file ? file.name : null);
                            }}
                          />
                          {fileLabel && (
                            <p className="muted" style={{ marginTop: "0.35rem", fontSize: "0.85rem" }}>
                              Selected: {fileLabel}
                            </p>
                          )}
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
                        {isSubmitting || postComment.isPending ? "Sending…" : "Send message"}
                      </button>
                    )}
                  </form.Subscribe>
                </form>
              )}
            </aside>
          </div>
        )}
      </div>
    </>
  );
}
