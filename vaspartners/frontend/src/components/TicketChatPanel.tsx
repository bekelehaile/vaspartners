"use client";

import { useEffect, useMemo, useRef, useState } from "react";
import { useForm } from "@tanstack/react-form";
import { usePostTicketComment, useTicketMessages } from "@/hooks/use-customer";
import { getToken, type TicketMessage } from "@/lib/api";
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

export function TicketChatPanel({
  publicId,
  chatLocked,
  maxKb = 2048,
  initialMessages = [],
  initialHasMoreOlder = false,
  initialTotal,
}: {
  publicId: string;
  chatLocked?: boolean;
  maxKb?: number;
  initialMessages?: TicketMessage[];
  initialHasMoreOlder?: boolean;
  initialTotal?: number;
}) {
  const thread = useTicketMessages(publicId, {
    initialMessages,
    initialHasMoreOlder,
    initialTotal,
    pollMs: 8000,
  });
  const postComment = usePostTicketComment(publicId);
  const [fileLabel, setFileLabel] = useState<string | null>(null);
  const [downloadError, setDownloadError] = useState<string | null>(null);
  const scrollerRef = useRef<HTMLDivElement | null>(null);
  const stickToBottom = useRef(true);
  const prevNewestId = useRef<number | null>(null);

  const messages = useMemo(
    () =>
      [...thread.messages].sort((a, b) => {
        const at = a.created_at ? new Date(a.created_at).getTime() : a.id;
        const bt = b.created_at ? new Date(b.created_at).getTime() : b.id;
        return at - bt;
      }),
    [thread.messages],
  );

  useEffect(() => {
    const el = scrollerRef.current;
    if (!el || messages.length === 0) return;
    const newestId = messages[messages.length - 1]?.id ?? null;
    const isNewTail = newestId !== null && newestId !== prevNewestId.current;
    prevNewestId.current = newestId;
    if (stickToBottom.current || isNewTail) {
      el.scrollTop = el.scrollHeight;
    }
  }, [messages]);

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
      stickToBottom.current = true;
      await postComment.mutateAsync({
        body: parsed.body,
        attachment: file,
      });
      formApi.reset();
      setFileLabel(null);
    },
  });

  return (
    <aside className="panel chat-panel">
      <div className="chat-header">
        <div>
          <h2 style={{ margin: 0 }}>Messages</h2>
          <p className="muted" style={{ margin: "0.35rem 0 0" }}>
            Ongoing chat with your account manager
            {thread.total > 0 ? ` · ${thread.total} message${thread.total === 1 ? "" : "s"}` : ""}.
            PDF up to {maxKb} KB.
          </p>
        </div>
      </div>

      {(postComment.isError || downloadError || thread.error) && (
        <div className="alert" style={{ marginTop: "0.75rem" }}>
          {downloadError
            ? downloadError
            : postComment.isError
              ? postComment.error instanceof Error
                ? postComment.error.message
                : "Could not send message"
              : thread.error instanceof Error
                ? thread.error.message
                : "Could not load messages"}
        </div>
      )}

      <div
        className="chat-thread"
        role="log"
        aria-live="polite"
        ref={scrollerRef}
        onScroll={(e) => {
          const el = e.currentTarget;
          const distanceFromBottom = el.scrollHeight - el.scrollTop - el.clientHeight;
          stickToBottom.current = distanceFromBottom < 80;
        }}
      >
        {thread.hasMoreOlder && (
          <button
            type="button"
            className="chat-load-older"
            disabled={thread.loadingOlder}
            onClick={() => void thread.loadOlder()}
          >
            {thread.loadingOlder ? "Loading…" : "Load earlier messages"}
          </button>
        )}

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
                  {m.created_at && <span>{new Date(m.created_at).toLocaleString()}</span>}
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
                    {m.attachment_size_bytes ? ` (${formatBytes(m.attachment_size_bytes)})` : ""}
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
                    placeholder="Continue the conversation…"
                    onKeyDown={(e) => {
                      if (e.key === "Enter" && !e.shiftKey) {
                        e.preventDefault();
                        void form.handleSubmit();
                      }
                    }}
                  />
                  {err && <p className="field-error">{err}</p>}
                </div>
              );
            }}
          </form.Field>

          <form.Field name="attachment">
            {(field) => {
              const err =
                form.state.submissionAttempts > 0 ? fieldError(field.state.meta.errors) : null;
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
              <button className="btn-primary" disabled={isSubmitting || postComment.isPending}>
                {isSubmitting || postComment.isPending ? "Sending…" : "Send message"}
              </button>
            )}
          </form.Subscribe>
        </form>
      )}
    </aside>
  );
}
