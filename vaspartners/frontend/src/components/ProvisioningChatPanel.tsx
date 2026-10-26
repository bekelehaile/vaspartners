"use client";

import Link from "next/link";
import { useEffect, useMemo, useRef, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { getToken, type TicketMessage } from "@/lib/api";
import { api } from "@/lib/api";

function formatBytes(bytes?: number | null): string {
  if (!bytes || bytes < 1) return "";
  if (bytes < 1024) return `${bytes} B`;
  return `${(bytes / 1024).toFixed(1)} KB`;
}

const LONG_MESSAGE_CHARS = 280;

function ChatMessageBody({ body }: { body: string }) {
  const [expanded, setExpanded] = useState(false);
  const long = body.length > LONG_MESSAGE_CHARS;
  const shown = !long || expanded ? body : `${body.slice(0, LONG_MESSAGE_CHARS).trimEnd()}…`;

  return (
    <div className="chat-body">
      <p>{shown}</p>
      {long ? (
        <button type="button" className="chat-expand" onClick={() => setExpanded((v) => !v)}>
          {expanded ? "Show less" : "View full message"}
        </button>
      ) : null}
    </div>
  );
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

export function ProvisioningChatPanel({
  publicId,
  chatLocked,
  chatBlockReason,
  maxKb = 2048,
  initialMessages = [],
  subscriptionPublicId,
}: {
  publicId: string;
  chatLocked?: boolean;
  chatBlockReason?: "closed" | "not_ready" | string | null;
  maxKb?: number;
  initialMessages?: TicketMessage[];
  subscriptionPublicId?: string | null;
}) {
  const blockReason = chatBlockReason ?? (chatLocked ? "closed" : null);
  const qc = useQueryClient();
  const [body, setBody] = useState("");
  const [file, setFile] = useState<File | null>(null);
  const [fileLabel, setFileLabel] = useState<string | null>(null);
  const [downloadError, setDownloadError] = useState<string | null>(null);
  const scrollerRef = useRef<HTMLDivElement | null>(null);
  const stickToBottom = useRef(true);

  const thread = useQuery({
    queryKey: ["provisioning", publicId, "messages"],
    enabled: !!getToken() && !!publicId,
    refetchInterval: 8000,
    initialData: initialMessages.length
      ? { data: initialMessages, meta: { total: initialMessages.length } }
      : undefined,
    queryFn: async () => {
      const res = await api<{ data: TicketMessage[]; meta?: { total?: number } }>(
        `/provisioning/${encodeURIComponent(publicId)}/messages?limit=40`,
      );
      return { data: res.data ?? [], meta: res.meta };
    },
  });

  const messages = useMemo(
    () =>
      [...(thread.data?.data ?? [])].sort((a, b) => {
        const at = a.created_at ? new Date(a.created_at).getTime() : a.id;
        const bt = b.created_at ? new Date(b.created_at).getTime() : b.id;
        return at - bt;
      }),
    [thread.data?.data],
  );

  useEffect(() => {
    const el = scrollerRef.current;
    if (!el || messages.length === 0) return;
    if (stickToBottom.current) el.scrollTop = el.scrollHeight;
  }, [messages]);

  const post = useMutation({
    mutationFn: async () => {
      const form = new FormData();
      const text = body.trim();
      if (text) form.append("body", text);
      if (file) form.append("attachment", file);
      if (!text && !file) throw new Error("Enter a message or attach a PDF.");
      if (file && file.size > maxKb * 1024) {
        throw new Error(`PDF must be ${maxKb} KB or smaller`);
      }
      const res = await api<{ data: TicketMessage }>(
        `/provisioning/${encodeURIComponent(publicId)}/messages`,
        { method: "POST", body: form },
      );
      return res.data;
    },
    onSuccess: () => {
      setBody("");
      setFile(null);
      setFileLabel(null);
      stickToBottom.current = true;
      void thread.refetch();
      qc.invalidateQueries({ queryKey: ["provisioning", publicId] });
    },
  });

  return (
    <aside className="panel chat-panel">
      <div className="chat-header panel-section-head">
        <div>
          <h2>Messages</h2>
          <p className="muted">
            {blockReason === "not_ready"
              ? "Messaging opens after Ethio telecom shares credentials for you to configure."
              : blockReason === "closed"
                ? "Go Live is complete — use Subscription Manage for further changes."
                : `Chat with your account manager while configuring credentials. PDF up to ${maxKb} KB.`}
          </p>
        </div>
      </div>

      {(post.isError || downloadError || thread.isError) && (
        <div className="alert">
          {downloadError
            || (post.error instanceof Error ? post.error.message : null)
            || (thread.error instanceof Error ? thread.error.message : "Could not load messages")}
        </div>
      )}

      <div
        className="chat-thread"
        role="log"
        aria-live="polite"
        ref={scrollerRef}
        onScroll={(e) => {
          const el = e.currentTarget;
          stickToBottom.current = el.scrollHeight - el.scrollTop - el.clientHeight < 80;
        }}
      >
        {messages.length === 0 ? (
          <div className="portal-empty chat-empty">
            <p>
              {blockReason === "not_ready"
                ? "No messages yet. You can chat here once credentials are shared."
                : "No messages yet. Ask your account manager for help configuring the shared credentials."}
            </p>
          </div>
        ) : (
          messages.map((m) => {
            const mine = m.author_role === "contact";
            return (
              <div
                key={m.id}
                className={`chat-bubble ${mine ? "chat-bubble-mine" : "chat-bubble-staff"}`}
              >
                <div className="chat-meta">
                  <strong>{m.author_label}</strong>
                  {m.created_at ? <span>{new Date(m.created_at).toLocaleString()}</span> : null}
                </div>
                {m.body ? <ChatMessageBody body={m.body} /> : null}
                {m.has_attachment ? (
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
                ) : null}
              </div>
            );
          })
        )}
      </div>

      {blockReason === "not_ready" ? (
        <div className="mt-4">
          <p className="muted">
            Messaging is not available yet. Ethio telecom will share credentials when configuration
            is ready; then you can chat with your account manager here.
          </p>
        </div>
      ) : blockReason === "closed" || chatLocked ? (
        <div className="mt-4 space-y-2">
          <p className="muted">
            Messaging is closed after Go Live. Use Subscription Manage for renewals and further
            changes.
          </p>
          {subscriptionPublicId ? (
            <Link
              className="btn-primary inline-flex"
              href={`/portal/subscriptions/${encodeURIComponent(subscriptionPublicId)}`}
            >
              Open subscription
            </Link>
          ) : (
            <Link className="btn-primary inline-flex" href="/portal/subscriptions">
              Open subscriptions
            </Link>
          )}
        </div>
      ) : (
        <form
          className="mt-4 space-y-3"
          onSubmit={(e) => {
            e.preventDefault();
            post.mutate();
          }}
        >
          <div className="field">
            <label htmlFor="prv-chat-body">Your message</label>
            <textarea
              id="prv-chat-body"
              rows={3}
              value={body}
              onChange={(e) => setBody(e.target.value)}
              placeholder="Ask your account manager for help with configuration…"
              onKeyDown={(e) => {
                if (e.key === "Enter" && !e.shiftKey) {
                  e.preventDefault();
                  post.mutate();
                }
              }}
            />
          </div>
          <div className="field">
            <label htmlFor="prv-chat-pdf">Attach small PDF (optional)</label>
            <input
              id="prv-chat-pdf"
              type="file"
              accept="application/pdf,.pdf"
              onChange={(e) => {
                const next = e.target.files?.[0] || null;
                setFile(next);
                setFileLabel(next ? next.name : null);
              }}
            />
            {fileLabel ? (
              <p className="muted" style={{ marginTop: "0.35rem", fontSize: "0.85rem" }}>
                Selected: {fileLabel}
              </p>
            ) : null}
          </div>
          <button className="btn-primary" type="submit" disabled={post.isPending}>
            {post.isPending ? "Sending…" : "Send message"}
          </button>
        </form>
      )}
    </aside>
  );
}
