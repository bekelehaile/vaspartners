"use client";

import { Badge } from "@/components/ui/badge";
import { statusCopy, type Ticket } from "@/lib/api";
import { cn } from "@/lib/utils";

const order: Ticket["status"][] = ["open", "in_progress", "completed", "closed"];

export function StatusJourney({ status }: { status: Ticket["status"] }) {
  const activeIdx =
    status === "rejected"
      ? 1
      : Math.max(0, order.indexOf(status === "closed" ? "closed" : status));

  return (
    <ol className="journey" aria-label="Request progress">
      {order.map((step, i) => {
        const done =
          status === "closed"
            ? true
            : i < activeIdx ||
              (status !== "rejected" && i === activeIdx && status === step);
        const current =
          status === "rejected" ? i === 1 : i === activeIdx && status !== "closed";
        return (
          <li
            key={step}
            className={cn(
              "journey-step",
              (done || current) && "is-on",
              current && "is-current",
            )}
          >
            <span className="journey-dot" />
            <span className="journey-label">{statusCopy[step].label}</span>
          </li>
        );
      })}
      {status === "rejected" && (
        <li className="journey-step is-on is-current is-alert">
          <span className="journey-dot" />
          <span className="journey-label">Rejected</span>
        </li>
      )}
    </ol>
  );
}

const toneClass: Record<string, string> = {
  "tone-open":
    "border-transparent bg-[color-mix(in_oklab,var(--primary)_12%,white)] text-[color-mix(in_oklab,var(--primary)_45%,#146c2e)]",
  "tone-progress": "border-transparent bg-[#fff8e6] text-[#8a6500]",
  "tone-done": "border-transparent bg-[#e8f8ee] text-[#146c2e]",
  "tone-closed": "border-border bg-muted text-muted-foreground",
  "tone-alert": "border-transparent bg-destructive/10 text-destructive",
};

export function StatusPill({ status }: { status: Ticket["status"] }) {
  const copy = statusCopy[status] ?? statusCopy.open;
  return (
    <Badge
      variant="outline"
      className={cn(
        "gap-1.5 rounded-full px-2.5 py-0.5 font-semibold",
        toneClass[copy.tone] ?? toneClass["tone-open"],
      )}
    >
      <span
        aria-hidden
        className="size-1.5 shrink-0 rounded-full bg-current opacity-85"
      />
      {copy.label}
    </Badge>
  );
}
