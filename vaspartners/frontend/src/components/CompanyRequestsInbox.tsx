"use client";

import {
  useCancelCompanyRequest,
  useCompanyRequestsInbox,
  useDecideMembershipRequest,
  type CompanyRequestCard,
} from "@/hooks/use-contact";

function typeLabel(type: string, mode: "mine" | "membership"): string {
  switch (type) {
    case "attach":
      return mode === "membership" ? "Incoming join request" : "Your join request";
    case "detach":
      return "Leave company";
    case "transfer_ownership":
      return "Transfer ownership";
    case "company_profile":
      return "Company profile approval";
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
      return "Waiting for Ethio telecom admin";
    default:
      return null;
  }
}

function RequestCard({
  row,
  onDecide,
  onCancel,
  busy,
  mode,
}: {
  row: CompanyRequestCard;
  onDecide?: (publicId: string, decision: "approve" | "reject") => void;
  onCancel?: (publicId: string) => void;
  busy: boolean;
  mode: "mine" | "membership";
}) {
  const wait = awaitingLabel(row.awaiting);

  return (
    <article className="company-request-card">
      <p className="company-request-card-title">
        <strong>{typeLabel(row.type, mode)}</strong>
        <span className={`company-request-status is-${row.status}`}>
          {statusLabel(row.status)}
        </span>
      </p>
      {row.company?.name && (
        <p className="muted company-request-meta">
          Company: <strong>{row.company.name}</strong>
          {row.company.tin ? ` · TIN ${row.company.tin}` : ""}
        </p>
      )}
      {mode === "membership" && row.applicant?.name && (
        <p className="company-request-meta">
          Partner asking to join: <strong>{row.applicant.name}</strong>
          {row.applicant.phone_number ? ` · ${row.applicant.phone_number}` : ""}
          {row.applicant.email ? ` · ${row.applicant.email}` : ""}
        </p>
      )}
      {row.target_contact?.name && (
        <p className="muted company-request-meta">
          Proposed new owner: {row.target_contact.name}
        </p>
      )}
      {mode === "mine" && wait && <p className="muted company-request-meta">{wait}</p>}
      {row.contact_note && (
        <p className="muted company-request-meta">
          {mode === "membership" ? "Their note" : "Your note"}: {row.contact_note}
        </p>
      )}
      {row.decision_note && (
        <p className="muted company-request-meta">Decision note: {row.decision_note}</p>
      )}
      {row.decided_by && row.decided_by !== "—" && row.status !== "pending" && (
        <p className="muted company-request-meta">Decided by: {row.decided_by}</p>
      )}
      <div className="company-request-actions">
        {mode === "membership" && row.can_approve && onDecide && (
          <button
            type="button"
            className="btn-primary"
            disabled={busy}
            onClick={() => onDecide(row.public_id, "approve")}
          >
            Approve membership
          </button>
        )}
        {mode === "membership" && row.can_reject && onDecide && (
          <button
            type="button"
            className="btn-ghost"
            disabled={busy}
            onClick={() => onDecide(row.public_id, "reject")}
          >
            Reject
          </button>
        )}
        {mode === "mine" &&
          row.can_cancel &&
          onCancel &&
          row.kind === "membership_change" && (
            <button
              type="button"
              className="btn-ghost"
              disabled={busy}
              onClick={() => onCancel(row.public_id)}
            >
              Cancel my request
            </button>
          )}
      </div>
    </article>
  );
}

/** Requests the partner submitted (join, leave, transfer, company profile). */
export function MyCompanyRequestsPanel({ enabled }: { enabled: boolean }) {
  const inbox = useCompanyRequestsInbox(enabled);
  const cancel = useCancelCompanyRequest();
  const submitted = inbox.data?.submitted ?? [];
  const busy = cancel.isPending;

  if (!enabled) {
    return null;
  }

  return (
    <section
      className="company-request-list-card"
      aria-labelledby="my-company-requests-heading"
    >
      <h2 id="my-company-requests-heading" className="sr-only">
        Your company requests
      </h2>

      {inbox.isLoading && (
        <div className="portal-empty">
          <p>Loading your requests…</p>
        </div>
      )}
      {inbox.isError && (
        <div className="alert" style={{ margin: "1rem 1.15rem" }}>
          {inbox.error instanceof Error
            ? inbox.error.message
            : "Could not load your requests"}
        </div>
      )}

      {!inbox.isLoading && submitted.length === 0 && (
        <div className="portal-empty">
          <p>
            You have not submitted any company requests yet. Join or create a company from
            Settings, then track those submissions here — not under Membership requests.
          </p>
        </div>
      )}

      {submitted.length > 0 && (
        <div className="company-request-list">
          {submitted.map((row) => (
            <RequestCard
              key={`mine-${row.kind}-${row.public_id}`}
              row={row}
              mode="mine"
              busy={busy}
              onCancel={(publicId) => void cancel.mutateAsync(publicId)}
            />
          ))}
        </div>
      )}

      {cancel.isError && (
        <div className="alert" style={{ margin: "0 1.15rem 1rem" }}>
          {cancel.error instanceof Error
            ? cancel.error.message
            : "Could not cancel request"}
        </div>
      )}
    </section>
  );
}

/** Join requests for companies the partner owns. */
export function MembershipRequestsPanel({ enabled }: { enabled: boolean }) {
  const inbox = useCompanyRequestsInbox(enabled);
  const decide = useDecideMembershipRequest();
  const toReview = inbox.data?.to_review ?? [];
  const summary = inbox.data?.summary;
  const busy = decide.isPending;
  const pendingCount = summary?.to_review_pending ?? 0;

  if (!enabled) {
    return null;
  }

  return (
    <section
      className="company-request-list-card"
      aria-labelledby="membership-requests-list-heading"
    >
      <h2 id="membership-requests-list-heading" className="sr-only">
        Membership requests
        {pendingCount > 0 ? ` (${pendingCount} waiting)` : ""}
      </h2>

      {inbox.isLoading && (
        <div className="portal-empty">
          <p>Loading membership requests…</p>
        </div>
      )}
      {inbox.isError && (
        <div className="alert" style={{ margin: "1rem 1.15rem" }}>
          {inbox.error instanceof Error
            ? inbox.error.message
            : "Could not load membership requests"}
        </div>
      )}

      {!inbox.isLoading && toReview.length === 0 && (
        <div className="portal-empty">
          <p>
            No partners are waiting for your approval. Only company owners (or members
            granted approval rights) see incoming join requests here. Your own submissions
            stay under Company requests.
          </p>
        </div>
      )}

      {toReview.length > 0 && (
        <div className="company-request-list">
          {toReview.map((row) => (
            <RequestCard
              key={`membership-${row.kind}-${row.public_id}`}
              row={row}
              mode="membership"
              busy={busy}
              onDecide={(publicId, decision) =>
                void decide.mutateAsync({ public_id: publicId, decision })
              }
            />
          ))}
        </div>
      )}

      {decide.isError && (
        <div className="alert" style={{ margin: "0 1.15rem 1rem" }}>
          {decide.error instanceof Error
            ? decide.error.message
            : "Could not update membership request"}
        </div>
      )}
    </section>
  );
}
