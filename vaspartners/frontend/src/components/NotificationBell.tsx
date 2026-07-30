"use client";

import Link from "next/link";
import { useEffect, useId, useRef, useState, type CSSProperties } from "react";
import { createPortal } from "react-dom";
import {
  type AppNotification,
  useClearAllNotifications,
  useClearNotification,
  useMarkAllNotificationsRead,
  useMarkNotificationRead,
  useNotifications,
} from "@/hooks/use-contact";

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
    case "company_profile_approved":
    case "company_tin_validated":
    case "documents_passed":
      return "success";
    case "documents_need_attention":
    case "ticket_rejected":
    case "company_profile_rejected":
    case "company_profile_pending":
    case "company_tin_invalid":
    case "company_erca_name_mismatch":
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
  const [mounted, setMounted] = useState(false);
  const [panelStyle, setPanelStyle] = useState<CSSProperties | undefined>();
  const rootRef = useRef<HTMLDivElement>(null);
  const panelRef = useRef<HTMLDivElement>(null);
  const panelId = useId();
  const { data, isLoading, isError, error } = useNotifications({ enabled: true });
  const markRead = useMarkNotificationRead();
  const markAll = useMarkAllNotificationsRead();
  const clearOne = useClearNotification();
  const clearAll = useClearAllNotifications();

  const items = data?.items ?? [];
  const unread = data?.unreadCount ?? 0;

  useEffect(() => {
    setMounted(true);
  }, []);

  useEffect(() => {
    if (!open) return;

    const placePanel = () => {
      const bell = rootRef.current;
      if (!bell) return;
      const rect = bell.getBoundingClientRect();
      const gutter = window.innerWidth < 768 ? 12 : window.innerWidth < 1024 ? 24 : 32;
      const panelWidth = Math.min(
        window.innerWidth < 768 ? window.innerWidth - gutter * 2 : 420,
        window.innerWidth - gutter * 2,
      );
      // Keep the panel flush to the bell's right edge (rightmost tools cluster).
      const right = Math.max(gutter, window.innerWidth - rect.right);
      const top = Math.min(
        rect.bottom + 8,
        Math.max(gutter, window.innerHeight - 120),
      );
      setPanelStyle({
        position: "fixed",
        top,
        right,
        left: "auto",
        width: panelWidth,
        maxHeight: `min(${window.innerWidth < 768 ? "70dvh" : "75vh"}, 32rem)`,
      });
    };

    placePanel();
    window.addEventListener("resize", placePanel);
    window.addEventListener("scroll", placePanel, true);
    return () => {
      window.removeEventListener("resize", placePanel);
      window.removeEventListener("scroll", placePanel, true);
    };
  }, [open]);

  useEffect(() => {
    if (!open) return;

    const onPointerDown = (e: PointerEvent) => {
      const target = e.target as Node;
      if (rootRef.current?.contains(target)) return;
      if (panelRef.current?.contains(target)) return;
      setOpen(false);
    };
    const onKey = (e: KeyboardEvent) => {
      if (e.key === "Escape") setOpen(false);
    };

    document.addEventListener("pointerdown", onPointerDown);
    document.addEventListener("keydown", onKey);
    return () => {
      document.removeEventListener("pointerdown", onPointerDown);
      document.removeEventListener("keydown", onKey);
    };
  }, [open]);

  const panel =
    open && mounted
      ? createPortal(
          <div
            ref={panelRef}
            className="notif-panel notif-panel-portal"
            role="dialog"
            aria-label="Notifications"
            id={panelId}
            style={panelStyle}
          >
            <div className="notif-panel-head">
              <div>
                <strong>Notifications</strong>
                <p className="notif-panel-sub">
                  {isError
                    ? "Could not load notifications"
                    : unread > 0
                      ? `${unread} unread update${unread === 1 ? "" : "s"}`
                      : "You are up to date"}
                </p>
              </div>
              {(unread > 0 || items.length > 0) && (
                <div className="notif-panel-actions">
                  {unread > 0 && (
                    <button
                      type="button"
                      className="notif-mark-all"
                      disabled={markAll.isPending || clearAll.isPending}
                      onClick={() => markAll.mutate()}
                    >
                      Mark all read
                    </button>
                  )}
                  {items.length > 0 && (
                    <button
                      type="button"
                      className="notif-clear-all"
                      disabled={clearAll.isPending || markAll.isPending}
                      onClick={() => clearAll.mutate()}
                    >
                      Clear all
                    </button>
                  )}
                </div>
              )}
            </div>

            <div className="notif-panel-body">
              {isLoading && <p className="muted notif-empty">Loading notifications…</p>}
              {isError && (
                <p className="alert notif-empty" role="alert">
                  {error instanceof Error ? error.message : "Could not load notifications."}
                </p>
              )}
              {!isLoading && !isError && !items.length && (
                <div className="notif-empty-state">
                  <IconFor template="ticket_submitted" />
                  <p>No notifications yet</p>
                  <span className="muted">
                    Updates about your company and service requests will appear here.
                  </span>
                </div>
              )}
              {items.map((n) => (
                <NotificationRow
                  key={n.id}
                  notification={n}
                  clearing={clearOne.isPending && clearOne.variables === n.id}
                  onOpen={() => {
                    if (!n.read_at) markRead.mutate(n.id);
                    setOpen(false);
                  }}
                  onClear={() => clearOne.mutate(n.id)}
                />
              ))}
            </div>
          </div>,
          document.body,
        )
      : null;

  return (
    <div className="notif-bell" ref={rootRef}>
      <button
        type="button"
        className="notif-bell-btn"
        aria-expanded={open}
        aria-haspopup="dialog"
        aria-controls={open ? panelId : undefined}
        aria-label={unread ? `${unread} unread notifications` : "Notifications"}
        onClick={() => setOpen((v) => !v)}
      >
        <BellIcon />
        {unread > 0 && (
          <span className="notif-badge">{unread > 9 ? "9+" : unread}</span>
        )}
      </button>
      {panel}
    </div>
  );
}

function NotificationRow({
  notification: n,
  clearing,
  onOpen,
  onClear,
}: {
  notification: AppNotification;
  clearing?: boolean;
  onOpen: () => void;
  onClear: () => void;
}) {
  const unreadItem = !n.read_at;

  return (
    <div className={`notif-item${unreadItem ? " is-unread" : ""}`}>
      <Link href={n.url || "/portal"} className="notif-item-link" onClick={onOpen}>
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
      <button
        type="button"
        className="notif-clear-one"
        aria-label="Clear notification"
        title="Clear"
        disabled={clearing}
        onClick={(e) => {
          e.preventDefault();
          e.stopPropagation();
          onClear();
        }}
      >
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden>
          <path d="M18 6 6 18" strokeLinecap="round" />
          <path d="m6 6 12 12" strokeLinecap="round" />
        </svg>
      </button>
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
