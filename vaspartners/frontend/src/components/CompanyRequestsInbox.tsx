"use client";

import { useState } from "react";
import {
  useCancelCompanyRequest,
  useCompanyRequestsInbox,
  useDecideMembershipRequest,
  type CompanyRequestCard,
} from "@/hooks/use-customer";

function typeLabel(type: string): string {
  switch (type) {
    case "attach":
      return "Join company";
    case "detach":
      return "Leave company";
    case "transfer_ownership":
      return "Transfer ownership";
    case "company_profile":
      return "Company profile";
    default:
      return type;
  }
}

function statusLabel(status: string): string {
  switch (status) {
    case "pending":
      return "Pending";
    case "approved":
      return "Approved";
    case "rejected":
      return "Rejected";
    default:
      return status;
  }
}

function awaitingLabel(awaiting?: string | null): string | null {
  switch (awaiting) {
    case "company_owner":
      return "Waiting for company owner";
    case "admin":
      return "Waiting for admin";
    default:
      return null;
  }
}

function RequestCard({
  row,
  onDecide,
  onCancel,
  busy,
}: {
  row: CompanyRequestCard;
  onDecide?: (publicId: string, decision: "approve" | "reject") => void;
  onCancel?: (publicId: string) => void;
  busy: boolean;
}) {
  const wait = awaitingLabel(row.awaiting);

  return (
    <div
      style={{
        borderTop: "1px solid color-mix(in oklab, var(--et-ink) 12%, white)",
        paddingTop: "1rem",
        marginTop: "1rem",
      }}
    >
      <p style={{ margin: "0 0 0.35rem" }}>
        <strong>{typeLabel(row.type)}</strong>
        {" · "}
        <span className="muted">{statusLabel(row.status)}</span>
        {row.company?.name ? ` · ${row.company.name}` : ""}
      </p>
      {row.direction === "to_review" && row.applicant?.name && (
        <p style={{ margin: "0 0 0.35rem" }}>
          Applicant: <strong>{row.applicant.name}</strong>
          {row.applicant.phone_number ? ` · ${row.applicant.phone_number}` : ""}
        </p>
      )}
      {row.target_customer?.name && (
        <p className="muted" style={{ margin: "0 0 0.35rem" }}>
          New owner: {row.target_customer.name}
        </p>
      )}
      {wait && <p className="muted">{wait}</p>}
      {row.customer_note && <p className="muted">Note: {row.customer_note}</p>}
      {row.decision_note && (
        <p className="muted">Decision note: {row.decision_note}</p>
      )}
      {row.decided_by && row.decided_by !== "—" && row.status !== "pending" && (
        <p className="muted">Decided by: {row.decided_by}</p>
      )}
      <div style={{ display: "flex", gap: "0.75rem", flexWrap: "wrap" }}>
        {row.can_approve && onDecide && (
          <button
            type="button"
            className="btn-primary"
            disabled={busy}
            onClick={() => onDecide(row.public_id, "approve")}
          >
            Approve
          </button>
        )}
        {row.can_reject && onDecide && (
          <button
            type="button"
            className="btn-ghost"
            disabled={busy}
            onClick={() => onDecide(row.public_id, "reject")}
          >
            Reject
          </button>
        )}
        {row.can_cancel && onCancel && row.kind === "membership_change" && (
          <button
            type="button"
            className="btn-ghost"
            disabled={busy}
            onClick={() => onCancel(row.public_id)}
          >
            Cancel request
          </button>
        )}
      </div>
    </div>
  );
}

/** Shared membership / company request inbox for partners (submitters + owners). */
export function CompanyRequestsInbox({ enabled }: { enabled: boolean }) {
  const [tab, setTab] = useState<"submitted" | "to_review">("submitted");
  const inbox = useCompanyRequestsInbox(enabled);
  const decide = useDecideMembershipRequest();
  const cancel = useCancelCompanyRequest();

  const submitted = inbox.data?.submitted ?? [];
  const toReview = inbox.data?.to_review ?? [];
  const summary = inbox.data?.summary;
  const busy = decide.isPending || cancel.isPending;

  if (!enabled) {
    return null;
  }

  return (
    <div className="panel" id="company-requests">
      <h2>Company &amp; membership requests</h2>
      <p className="muted">
        Track requests you submitted (join, transfer, company profile) and review
        membership joins for companies you own. Admins decide company profile and
        ownership transfers in the admin portal.
      </p>

      <div style={{ display: "flex", gap: "0.75rem", flexWrap: "wrap", marginBottom: "1rem" }}>
        <button
          type="button"
          className={tab === "submitted" ? "btn-primary" : "btn-ghost"}
          onClick={() => setTab("submitted")}
        >
          My requests
          {summary?.submitted_pending
            ? ` (${summary.submitted_pending} pending)`
            : ""}
        </button>
        <button
          type="button"
          className={tab === "to_review" ? "btn-primary" : "btn-ghost"}
          onClick={() => setTab("to_review")}
        >
          To review
          {summary?.to_review_pending
            ? ` (${summary.to_review_pending})`
            : ""}
        </button>
      </div>

      {inbox.isLoading && <p className="muted">Loading requests…</p>}
      {inbox.isError && (
        <div className="alert">
          {inbox.error instanceof Error
            ? inbox.error.message
            : "Could not load requests"}
        </div>
      )}

      {tab === "submitted" && !inbox.isLoading && submitted.length === 0 && (
        <p className="muted" style={{ marginBottom: 0 }}>
          You have not submitted any company or membership requests yet.
        </p>
      )}
      {tab === "to_review" && !inbox.isLoading && toReview.length === 0 && (
        <p className="muted" style={{ marginBottom: 0 }}>
          No membership join requests waiting for your approval.
        </p>
      )}

      {(tab === "submitted" ? submitted : toReview).map((row) => (
        <RequestCard
          key={`${row.kind}-${row.public_id}`}
          row={row}
          busy={busy}
          onDecide={
            tab === "to_review"
              ? (publicId, decision) =>
                  void decide.mutateAsync({ public_id: publicId, decision })
              : undefined
          }
          onCancel={
            tab === "submitted"
              ? (publicId) => void cancel.mutateAsync(publicId)
              : undefined
          }
        />
      ))}

      {(decide.isError || cancel.isError) && (
        <div className="alert" style={{ marginTop: "1rem" }}>
          {(decide.error || cancel.error) instanceof Error
            ? ((decide.error || cancel.error) as Error).message
            : "Could not update request"}
        </div>
      )}
    </div>
  );
}
