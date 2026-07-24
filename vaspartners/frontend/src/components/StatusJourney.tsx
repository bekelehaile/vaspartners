"use client";

import { statusCopy, Ticket } from "@/lib/api";

const order: Ticket["status"][] = ["open", "in_progress", "completed", "closed"];

export function StatusJourney({ status }: { status: Ticket["status"] }) {
  const activeIdx =
    status === "rejected" ? 1 : Math.max(0, order.indexOf(status === "closed" ? "closed" : status));

  return (
    <ol className="journey" aria-label="Request progress">
      {order.map((step, i) => {
        const done = status === "closed" ? true : i < activeIdx || (status !== "rejected" && i === activeIdx && status === step);
        const current = status === "rejected" ? i === 1 : i === activeIdx && status !== "closed";
        return (
          <li
            key={step}
            className={`journey-step ${done || current ? "is-on" : ""} ${current ? "is-current" : ""}`}
          >
            <span className="journey-dot" />
            <span className="journey-label">{statusCopy[step].label}</span>
          </li>
        );
      })}
      {status === "rejected" && (
        <li className="journey-step is-on is-current is-alert">
          <span className="journey-dot" />
          <span className="journey-label">Needs attention</span>
        </li>
      )}
    </ol>
  );
}

export function StatusPill({ status }: { status: Ticket["status"] }) {
  const copy = statusCopy[status] ?? statusCopy.open;
  return <span className={`pill ${copy.tone}`}>{copy.label}</span>;
}
