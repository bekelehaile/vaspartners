"use client";

import Link from "next/link";
import { useEffect, useRef, useState } from "react";
import {
  useMarkAllNotificationsRead,
  useMarkNotificationRead,
  useNotifications,
} from "@/hooks/use-customer";

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
    document.addEventListener("mousedown", onDoc);
    return () => document.removeEventListener("mousedown", onDoc);
  }, [open]);

  return (
    <div className="notif-bell" ref={rootRef}>
      <button
        type="button"
        className="notif-bell-btn"
        aria-expanded={open}
        aria-label={unread ? `${unread} unread notifications` : "Notifications"}
        onClick={() => setOpen((v) => !v)}
      >
        <BellIcon />
        {unread > 0 && <span className="notif-badge">{unread > 9 ? "9+" : unread}</span>}
      </button>

      {open && (
        <div className="notif-panel" role="dialog" aria-label="Notifications">
          <div className="notif-panel-head">
            <strong>Notifications</strong>
            {unread > 0 && (
              <button
                type="button"
                className="linkish"
                disabled={markAll.isPending}
                onClick={() => markAll.mutate()}
              >
                Mark all read
              </button>
            )}
          </div>

          <div className="notif-panel-body">
            {isLoading && <p className="muted notif-empty">Loading…</p>}
            {!isLoading && !items.length && (
              <p className="muted notif-empty">No notifications yet.</p>
            )}
            {items.map((n) => {
              const unreadItem = !n.read_at;
              const content = (
                <>
                  <span className="notif-title">
                    {unreadItem && <i className="notif-dot" aria-hidden />}
                    {n.title}
                  </span>
                  <span className="notif-body">{n.body}</span>
                  <span className="notif-time">
                    {n.created_at ? new Date(n.created_at).toLocaleString() : ""}
                  </span>
                </>
              );

              return (
                <Link
                  key={n.id}
                  href={n.url || "/portal"}
                  className={`notif-item${unreadItem ? " is-unread" : ""}`}
                  onClick={() => {
                    if (unreadItem) markRead.mutate(n.id);
                    setOpen(false);
                  }}
                >
                  {content}
                </Link>
              );
            })}
          </div>
        </div>
      )}
    </div>
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
