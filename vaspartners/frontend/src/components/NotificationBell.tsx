"use client";

import Link from "next/link";
import { useEffect, useRef, useState } from "react";
import {
  type AppNotification,
  useMarkAllNotificationsRead,
  useMarkNotificationRead,
  useNotifications,
} from "@/hooks/use-customer";

function formatRelativeTime(iso?: string | null): string {
  if (!iso) return "";
  const then = new Date(iso).getTime();
  if (Number.isNaN(then)) return "";
  const diffSec = Math.round((Date.now() - then) / 1000);
  if (diffSec < 45) return "Just now";
  if (diffSec < 3600) {
    const m = Math.floor(diffSec / 60);
    return `${m} min${m === 1 ? "" : "s"} ago`;
  }
  if (diffSec < 86400) {
    const h = Math.floor(diffSec / 3600);
    return `${h} hour${h === 1 ? "" : "s"} ago`;
  }
  if (diffSec < 86400 * 7) {
    const d = Math.floor(diffSec / 86400);
    return `${d} day${d === 1 ? "" : "s"} ago`;
  }
  return new Date(iso).toLocaleDateString(undefined, {
    day: "numeric",
    month: "short",
    year: "numeric",
  });
}

function toneFor(template?: string | null): string {
  switch (template) {
    case "ticket_completed":
    case "profile_completed":
      return "success";
    case "documents_need_attention":
    case "ticket_rejected":
      return "warning";
    case "ticket_closed":
      return "muted";
    case "ticket_in_progress":
      return "info";
    default:
      return "default";
  }
}

function IconFor({ template }: { template?: string | null }) {
  const tone = toneFor(template);
  return (
    <span className={`notif-icon notif-icon-${tone}`} aria-hidden>
      {tone === "success" ? (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
          <path d="M20 6 9 17l-5-5" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
      ) : tone === "warning" ? (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
          <path d="M12 9v4" strokeLinecap="round" />
          <path d="M12 17h.01" strokeLinecap="round" />
          <path
            d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"
            strokeLinejoin="round"
          />
        </svg>
      ) : tone === "info" ? (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
          <circle cx="12" cy="12" r="10" />
          <path d="M12 16v-4" strokeLinecap="round" />
          <path d="M12 8h.01" strokeLinecap="round" />
        </svg>
      ) : (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
          <path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9" strokeLinecap="round" strokeLinejoin="round" />
          <path d="M10.3 21a1.94 1.94 0 0 0 3.4 0" />
        </svg>
      )}
    </span>
  );
}

export function NotificationBell() {
  const [open, setOpen] = useState(false);
  const rootRef = useRef<HTMLDivElement>(null);
  const { data, isLoading } = useNotifications({ enabled: true });
  const markRead = useMarkNotificationRead();
  const markAll = useMarkAllNotificationsRead();

  const items = data?.items ?? [];
  const unread = data?.unreadCount ?? 0;

  useEffect(() => {
    if (!open) return;
    const onDoc = (e: MouseEvent) => {
      if (!rootRef.current?.contains(e.target as Node)) setOpen(false);
    };
    const onKey = (e: KeyboardEvent) => {
      if (e.key === "Escape") setOpen(false);
    };
    document.addEventListener("mousedown", onDoc);
    document.addEventListener("keydown", onKey);
    return () => {
      document.removeEventListener("mousedown", onDoc);
      document.removeEventListener("keydown", onKey);
    };
  }, [open]);

  return (
    <div className="notif-bell" ref={rootRef}>
      <button
        type="button"
        className="notif-bell-btn"
        aria-expanded={open}
        aria-haspopup="dialog"
        aria-label={unread ? `${unread} unread notifications` : "Notifications"}
        onClick={() => setOpen((v) => !v)}
      >
        <BellIcon />
        {unread > 0 && (
          <span className="notif-badge">{unread > 9 ? "9+" : unread}</span>
        )}
      </button>

      {open && (
        <div className="notif-panel" role="dialog" aria-label="Notifications">
          <div className="notif-panel-head">
            <div>
              <strong>Notifications</strong>
              <p className="notif-panel-sub">
                {unread > 0
                  ? `${unread} unread update${unread === 1 ? "" : "s"}`
                  : "You are up to date"}
              </p>
            </div>
            {unread > 0 && (
              <button
                type="button"
                className="notif-mark-all"
                disabled={markAll.isPending}
                onClick={() => markAll.mutate()}
              >
                Mark all read
              </button>
            )}
          </div>

          <div className="notif-panel-body">
            {isLoading && <p className="muted notif-empty">Loading notifications…</p>}
            {!isLoading && !items.length && (
              <div className="notif-empty-state">
                <IconFor template="ticket_submitted" />
                <p>No notifications yet</p>
                <span className="muted">
                  Updates about your service requests will appear here.
                </span>
              </div>
            )}
            {items.map((n) => (
              <NotificationRow
                key={n.id}
                notification={n}
                onOpen={() => {
                  if (!n.read_at) markRead.mutate(n.id);
                  setOpen(false);
                }}
              />
            ))}
          </div>
        </div>
      )}
    </div>
  );
}

function NotificationRow({
  notification: n,
  onOpen,
}: {
  notification: AppNotification;
  onOpen: () => void;
}) {
  const unreadItem = !n.read_at;

  return (
    <Link
      href={n.url || "/portal"}
      className={`notif-item${unreadItem ? " is-unread" : ""}`}
      onClick={onOpen}
    >
      <IconFor template={n.template} />
      <div className="notif-item-main">
        <div className="notif-item-top">
          <span className="notif-title">
            {unreadItem && <i className="notif-dot" aria-hidden />}
            {n.title}
          </span>
          <time className="notif-time" dateTime={n.created_at || undefined}>
            {formatRelativeTime(n.created_at)}
          </time>
        </div>
        <p className="notif-body">{n.body}</p>
        <div className="notif-item-meta">
          {n.tt_number && <span className="notif-ref">{n.tt_number}</span>}
          <span className="notif-cta">View details</span>
        </div>
      </div>
    </Link>
  );
}

function BellIcon() {
  return (
    <svg
      xmlns="http://www.w3.org/2000/svg"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden
      width="18"
      height="18"
    >
      <path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9" />
      <path d="M10.3 21a1.94 1.94 0 0 0 3.4 0" />
    </svg>
  );
}
