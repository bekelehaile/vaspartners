"use client";

import { useState } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { CompanyProfileForm } from "@/components/CompanyProfileForm";
import { FaydaIdentityPanel } from "@/components/FaydaIdentityPanel";
import { PortalPageHeader } from "@/components/PortalPageHeader";
import {
  useAttachCompany,
  useCompanyMembers,
  useCustomer,
  useDecideMembershipRequest,
  useDetachCompany,
  useLookupCompany,
  useMembershipRequests,
  useSwitchCompany,
  useTransferOwnership,
} from "@/hooks/use-customer";
import { queryKeys } from "@/lib/query-keys";

export default function CompanyProfilePage() {
  const queryClient = useQueryClient();
  const { data: me } = useCustomer();
  const switchCompany = useSwitchCompany();
  const [creatingAnother, setCreatingAnother] = useState(false);
  const membershipDisabled =
    !!me?.company_id && me?.company_membership_active === false;
  const awaitingApproval =
    !!me?.company_id &&
    !membershipDisabled &&
    me?.company?.is_approved === false;
  const isLinked = !!me?.profile_completed && !!me?.company_id && !membershipDisabled;
  const isOwner = (isLinked || awaitingApproval) && me?.company_role === "owner";
  const canEditCompany = !!me?.company_can_edit;
  const pending = me?.pending_company_request;
  const [mode, setMode] = useState<"create" | "attach">("create");
  const [tin, setTin] = useState("");
  const [license, setLicense] = useState("");
  const [note, setNote] = useState("");
  const [lookupTin, setLookupTin] = useState("");
  const [lookupLicense, setLookupLicense] = useState("");
  const lookup = useLookupCompany(lookupTin, lookupLicense);
  const attach = useAttachCompany();
  const detach = useDetachCompany();
  const transfer = useTransferOwnership();
  const membershipRequests = useMembershipRequests(!!isOwner && isLinked);
  const companyMembers = useCompanyMembers(!!isLinked && !pending);
  const decideMembership = useDecideMembershipRequest();
  const [detachNote, setDetachNote] = useState("");
  const [transferTarget, setTransferTarget] = useState("");
  const [transferNote, setTransferNote] = useState("");
  const [transferLetter, setTransferLetter] = useState<File | null>(null);

  const waitingFor =
    pending?.type === "attach"
      ? "company owner"
      : pending?.type === "transfer_ownership"
        ? "admin"
        : "admin";

  const transferCandidates = (companyMembers.data ?? []).filter(
    (m) => m.role !== "owner" && m.is_active !== false && m.public_id,
  );

  const approvalLabel =
    me?.company?.approval_status === "rejected"
      ? "Company profile rejected — update and resubmit"
      : "Company profile pending admin approval";

  return (
    <>
      <PortalPageHeader
        kicker={isLinked || awaitingApproval ? "Settings" : "Welcome"}
        title={
          membershipDisabled
            ? "Membership disabled"
            : awaitingApproval
              ? approvalLabel
              : isLinked
                ? "Company & identity"
                : pending
                  ? "Company request pending"
                  : "Link your Fayda account to a company"
        }
        description={
          membershipDisabled
            ? "Your access to this company has been disabled by an administrator."
            : awaitingApproval
              ? me?.company?.approval_status === "rejected"
                ? `Admin feedback: ${me?.company?.approval_note || "Please complete the required company information and resubmit."}`
                : "You are the company owner. An administrator must approve this unique TIN before you can request VAS services."
              : isLinked
                ? `Three sections: your Fayda identity, the company profile for ${me?.company_name || me?.company?.name || "this organisation"}, and members whose details come from Fayda.`
                : pending
                  ? `Your ${pending.type} request for ${pending.company?.name || "a company"} is waiting for ${waitingFor} approval. VAS services stay locked until the company TIN is approved.`
                  : `Hello${me?.name ? `, ${me.name.split(" ")[0]}` : ""}. Create a new company with a unique TIN + license for admin approval, or request to join an existing approved company. You cannot use VAS services until that TIN is approved.`
        }
      />

      <div className="section company-section section-flush">
        {(me?.memberships?.length ?? 0) > 0 && !membershipDisabled && (
          <div className="panel">
            <h2>Your companies</h2>
            <p className="muted">
              Subscriptions and manage-service requests use the selected company. You can be
              owner of some companies and member of others.
            </p>
            <ul style={{ listStyle: "none", padding: 0, margin: "0 0 1rem" }}>
              {(me?.memberships ?? []).map((m) => (
                <li
                  key={`${m.company_public_id}-${m.role}`}
                  style={{
                    display: "flex",
                    gap: "0.75rem",
                    flexWrap: "wrap",
                    alignItems: "center",
                    padding: "0.65rem 0",
                    borderTop: "1px solid color-mix(in oklab, var(--et-ink) 10%, white)",
                  }}
                >
                  <div style={{ flex: "1 1 12rem" }}>
                    <strong>{m.company_name || "Company"}</strong>
                    <span className="muted">
                      {" "}
                      · {m.role}
                      {m.is_current ? " · current" : ""}
                      {!m.is_active ? " · disabled" : ""}
                      {m.approval_status && m.approval_status !== "approved"
                        ? ` · ${m.approval_status}`
                        : ""}
                    </span>
                  </div>
                  {!m.is_current && m.is_active && m.company_public_id && (
                    <button
                      type="button"
                      className="btn-ghost"
                      disabled={switchCompany.isPending}
                      onClick={() =>
                        void switchCompany.mutateAsync(m.company_public_id!)
                      }
                    >
                      Switch
                    </button>
                  )}
                </li>
              ))}
            </ul>
            {isLinked && (
              <button
                type="button"
                className="btn-ghost"
                onClick={() => setCreatingAnother((v) => !v)}
              >
                {creatingAnother ? "Cancel" : "Create another company"}
              </button>
            )}
            {creatingAnother && (
              <div style={{ marginTop: "1rem" }}>
                <CompanyProfileForm
                  key="create-another"
                  me={me}
                  createNew
                  redirectTo="/portal/company"
                />
              </div>
            )}
            {switchCompany.isError && (
              <div className="alert" style={{ marginTop: "1rem" }}>
                {switchCompany.error instanceof Error
                  ? switchCompany.error.message
                  : "Could not switch company"}
              </div>
            )}
          </div>
        )}

        {membershipDisabled && (
          <div className="panel">
            <h2>Membership disabled</h2>
            <p className="muted" style={{ marginBottom: 0 }}>
              You remain linked to the company, but you cannot view company details or manage
              company services until an administrator re-enables your access.
            </p>
          </div>
        )}

        {!membershipDisabled && pending && (
          <div className="panel">
            <h2>Waiting for {waitingFor} decision</h2>
            <p className="muted">
              Type: <strong>{pending.type}</strong> · Status:{" "}
              <strong>{pending.status}</strong>
            </p>
            {pending.company && (
              <p>
                {pending.company.name} · TIN {pending.company.tin}
                {pending.company.license_number
                  ? ` · License ${pending.company.license_number}`
                  : ""}
              </p>
            )}
            {pending.type === "transfer_ownership" && pending.target_customer && (
              <p>
                Proposed new owner: <strong>{pending.target_customer.name}</strong>
              </p>
            )}
            {pending.customer_note && (
              <p className="muted">Your note: {pending.customer_note}</p>
            )}
            {pending.type === "transfer_ownership" && (
              <p className="muted">
                Letter attached: {pending.has_letter ? "yes" : "no"}
              </p>
            )}
            <p className="muted" style={{ marginBottom: 0 }}>
              You will receive an SMS and in-app notification when the request is
              approved or rejected.
            </p>
          </div>
        )}

        {!membershipDisabled && !pending && !isLinked && !awaitingApproval && (
          <>
            <div className="journey-tabs" role="tablist" aria-label="Company onboarding">
              <button
                type="button"
                className={mode === "create" ? "is-active" : undefined}
                onClick={() => setMode("create")}
              >
                Create new company
              </button>
              <button
                type="button"
                className={mode === "attach" ? "is-active" : undefined}
                onClick={() => setMode("attach")}
              >
                Attach to existing
              </button>
            </div>

            {mode === "create" ? (
              <CompanyProfileForm
                key={`${me?.public_id ?? "company"}-create`}
                me={me}
                redirectTo="/portal/company"
              />
            ) : (
              <div className="panel">
                <h2>Join an existing company</h2>
                <p className="muted">
                  Enter the company TIN and license number for an admin-approved company. The
                  company owner must approve your membership before you join.
                </p>
                <div className="field">
                  <label htmlFor="attach-tin">Company TIN</label>
                  <input
                    id="attach-tin"
                    value={tin}
                    onChange={(e) => setTin(e.target.value)}
                    placeholder="Registered TIN"
                  />
                </div>
                <div className="field">
                  <label htmlFor="attach-license">License number</label>
                  <input
                    id="attach-license"
                    value={license}
                    onChange={(e) => setLicense(e.target.value)}
                    placeholder="Business / trade license number"
                  />
                </div>
                <div className="field">
                  <label htmlFor="attach-note">Note to owner (optional)</label>
                  <textarea
                    id="attach-note"
                    rows={3}
                    value={note}
                    onChange={(e) => setNote(e.target.value)}
                    placeholder="Your role or reason for joining…"
                  />
                </div>
                <div style={{ display: "flex", gap: "0.75rem", flexWrap: "wrap" }}>
                  <button
                    type="button"
                    className="btn-ghost"
                    disabled={
                      tin.trim().length < 5 ||
                      license.trim().length < 3 ||
                      lookup.isFetching
                    }
                    onClick={() => {
                      setLookupTin(tin.trim());
                      setLookupLicense(license.trim());
                    }}
                  >
                    {lookup.isFetching ? "Looking up…" : "Lookup company"}
                  </button>
                  <button
                    type="button"
                    className="btn-primary"
                    disabled={
                      attach.isPending ||
                      tin.trim().length < 5 ||
                      license.trim().length < 3
                    }
                    onClick={() =>
                      void attach
                        .mutateAsync({
                          company_tin: tin.trim(),
                          company_license_number: license.trim(),
                          note,
                        })
                        .then(() => {
                          void queryClient.invalidateQueries({
                            queryKey: queryKeys.customer.me,
                          });
                        })
                    }
                  >
                    {attach.isPending ? "Submitting…" : "Request membership"}
                  </button>
                </div>
                {lookupTin && lookupLicense && lookup.data && (
                  <p style={{ marginTop: "1rem" }}>
                    Found: <strong>{lookup.data.name}</strong> (TIN {lookup.data.tin} ·
                    License {lookup.data.license_number})
                  </p>
                )}
                {lookupTin && lookupLicense && lookup.isError && (
                  <div className="alert" style={{ marginTop: "1rem" }}>
                    {lookup.error instanceof Error
                      ? lookup.error.message
                      : "Company not found"}
                  </div>
                )}
                {attach.isError && (
                  <div className="alert" style={{ marginTop: "1rem" }}>
                    {attach.error instanceof Error
                      ? attach.error.message
                      : "Could not submit membership request"}
                  </div>
                )}
              </div>
            )}
          </>
        )}

        {!pending && awaitingApproval && (
          <div className="panel">
            <h2>Organisation details</h2>
            <div className="alert" role="status" style={{ marginBottom: "1rem" }}>
              VAS services are locked until an administrator approves this company TIN.
              Each TIN can only be registered once.
            </div>
            <p className="muted">
              {canEditCompany
                ? "You can update your company details while waiting for admin approval. Resubmitting sends the profile back for review."
                : "Waiting for admin."}
            </p>
            {canEditCompany ? (
              <CompanyProfileForm
                key={`${me?.public_id ?? "company"}-pending`}
                me={me}
                redirectTo="/portal/company"
              />
            ) : null}
          </div>
        )}

        {!pending && isLinked && (
          <div className="company-portal-stack">
            <div className="panel" id="fayda-identity-panel">
              <h2>1. Fayda identity</h2>
              <p className="muted">
                Your personal National ID details from Fayda
                {me?.company_role === "owner" ? " (you are the company owner)" : ""}.
                This is not company registration data.
              </p>
              <FaydaIdentityPanel
                id="fayda-identity"
                title="Your Fayda identity"
                description="Read-only. Contact Fayda support if anything is wrong."
                person={me ?? {}}
                badge={
                  me?.company_role === "owner" ? (
                    <span className="service-meta">Owner</span>
                  ) : (
                    <span className="service-meta">Member</span>
                  )
                }
              />
            </div>

            <div className="panel" id="company-profile-panel">
              <h2>2. Company profile</h2>
              <p className="muted">
                Organisation registration for this TIN. After approval, only administrators
                can change these records.
              </p>
              <section id="company-info" className="settings-block">
                <dl className="fayda-dl company-profile-dl">
                  <div>
                    <dt>Company name</dt>
                    <dd>{me?.company_name || me?.company?.name || "—"}</dd>
                  </div>
                  <div>
                    <dt>TIN</dt>
                    <dd>{me?.company_tin || me?.company?.tin || "—"}</dd>
                  </div>
                  <div>
                    <dt>License number</dt>
                    <dd>
                      {me?.company_license_number || me?.company?.license_number || "—"}
                    </dd>
                  </div>
                  <div>
                    <dt>Approval</dt>
                    <dd>{me?.company?.approval_status || "approved"}</dd>
                  </div>
                  <div>
                    <dt>Company phone</dt>
                    <dd>{me?.company_phone || me?.company?.phone || "—"}</dd>
                  </div>
                  <div>
                    <dt>Company email</dt>
                    <dd>{me?.company_email || me?.company?.email || "—"}</dd>
                  </div>
                  <div style={{ gridColumn: "1 / -1" }}>
                    <dt>Address</dt>
                    <dd>{me?.company_address || me?.company?.address || "—"}</dd>
                  </div>
                </dl>
              </section>
            </div>

            <div className="panel" id="company-members-panel">
              <h2>3. Company members</h2>
              <p className="muted">
                Partners linked to this company. Names and ID details come from each
                person’s Fayda sign-in.
              </p>
              {companyMembers.isLoading && (
                <p className="muted">Loading members…</p>
              )}
              {companyMembers.isError && (
                <div className="alert">
                  {companyMembers.error instanceof Error
                    ? companyMembers.error.message
                    : "Could not load members"}
                </div>
              )}
              <div className="company-members-list">
                {(companyMembers.data ?? []).map((member, index) => (
                  <FaydaIdentityPanel
                    key={member.public_id || `member-${index}`}
                    id={
                      member.public_id
                        ? `member-${member.public_id}`
                        : undefined
                    }
                    title={member.name || "Partner"}
                    description="Fayda identity for this membership."
                    person={member}
                    badge={
                      <>
                        <span className="service-meta">
                          {member.role === "owner" ? "Owner" : "Member"}
                        </span>
                        {member.is_active === false ? (
                          <span className="service-meta">Access disabled</span>
                        ) : null}
                        {member.public_id && me?.public_id === member.public_id ? (
                          <span className="service-meta">You</span>
                        ) : null}
                      </>
                    }
                  />
                ))}
              </div>
              {!companyMembers.isLoading &&
                (companyMembers.data?.length ?? 0) === 0 && (
                  <p className="muted" style={{ marginBottom: 0 }}>
                    No members found for this company yet.
                  </p>
                )}
            </div>

            {isOwner && (
              <div className="panel" id="membership-requests">
                <h2>Membership requests</h2>
                <p className="muted">
                  Approve or reject partners who asked to join your company.
                </p>
                {membershipRequests.isLoading && (
                  <p className="muted">Loading requests…</p>
                )}
                {!membershipRequests.isLoading &&
                  (membershipRequests.data?.length ?? 0) === 0 && (
                    <p className="muted" style={{ marginBottom: 0 }}>
                      No pending membership requests.
                    </p>
                  )}
                {(membershipRequests.data ?? []).map((req) => (
                  <div
                    key={req.public_id}
                    style={{
                      borderTop: "1px solid color-mix(in oklab, var(--et-ink) 12%, white)",
                      paddingTop: "1rem",
                      marginTop: "1rem",
                    }}
                  >
                    <p style={{ margin: "0 0 0.35rem" }}>
                      <strong>{req.applicant?.name || "Partner"}</strong>
                      {req.applicant?.phone_number
                        ? ` · ${req.applicant.phone_number}`
                        : ""}
                    </p>
                    {req.customer_note && (
                      <p className="muted">Note: {req.customer_note}</p>
                    )}
                    <div style={{ display: "flex", gap: "0.75rem", flexWrap: "wrap" }}>
                      <button
                        type="button"
                        className="btn-primary"
                        disabled={decideMembership.isPending}
                        onClick={() =>
                          void decideMembership.mutateAsync({
                            public_id: req.public_id,
                            decision: "approve",
                          })
                        }
                      >
                        Approve
                      </button>
                      <button
                        type="button"
                        className="btn-ghost"
                        disabled={decideMembership.isPending}
                        onClick={() =>
                          void decideMembership.mutateAsync({
                            public_id: req.public_id,
                            decision: "reject",
                          })
                        }
                      >
                        Reject
                      </button>
                    </div>
                  </div>
                ))}
                {decideMembership.isError && (
                  <div className="alert" style={{ marginTop: "1rem" }}>
                    {decideMembership.error instanceof Error
                      ? decideMembership.error.message
                      : "Could not update request"}
                  </div>
                )}
              </div>
            )}

            {isOwner && (
              <div className="panel" id="transfer-ownership">
                <h2>Transfer ownership</h2>
                <p className="muted">
                  Required before you can leave. Choose an active member as the new owner and
                  upload a signed letter (PDF). An administrator must approve the transfer.
                </p>
                {companyMembers.isLoading && (
                  <p className="muted">Loading members…</p>
                )}
                {!companyMembers.isLoading && transferCandidates.length === 0 && (
                  <p className="muted" style={{ marginBottom: 0 }}>
                    No other active members yet. Approve a membership request first, then
                    transfer ownership.
                  </p>
                )}
                {transferCandidates.length > 0 && (
                  <>
                    <div className="field">
                      <label htmlFor="transfer-target">New owner</label>
                      <select
                        id="transfer-target"
                        value={transferTarget}
                        onChange={(e) => setTransferTarget(e.target.value)}
                      >
                        <option value="">Select a member…</option>
                        {transferCandidates.map((m) => (
                          <option key={m.public_id!} value={m.public_id!}>
                            {m.name || "Partner"}
                            {m.phone_number ? ` · ${m.phone_number}` : ""}
                          </option>
                        ))}
                      </select>
                    </div>
                    <div className="field">
                      <label htmlFor="transfer-letter">Letter (PDF, required)</label>
                      <input
                        id="transfer-letter"
                        type="file"
                        accept="application/pdf,.pdf"
                        onChange={(e) =>
                          setTransferLetter(e.target.files?.[0] ?? null)
                        }
                      />
                    </div>
                    <div className="field">
                      <label htmlFor="transfer-note">Note (optional)</label>
                      <textarea
                        id="transfer-note"
                        rows={3}
                        value={transferNote}
                        onChange={(e) => setTransferNote(e.target.value)}
                      />
                    </div>
                    {transfer.isError && (
                      <div className="alert">
                        {transfer.error instanceof Error
                          ? transfer.error.message
                          : "Could not submit transfer"}
                      </div>
                    )}
                    <button
                      type="button"
                      className="btn-primary"
                      disabled={
                        transfer.isPending ||
                        !transferTarget ||
                        !transferLetter
                      }
                      onClick={() => {
                        if (!transferLetter) return;
                        void transfer
                          .mutateAsync({
                            target_customer: transferTarget,
                            letter: transferLetter,
                            note: transferNote,
                          })
                          .then(() => {
                            setTransferTarget("");
                            setTransferNote("");
                            setTransferLetter(null);
                          });
                      }}
                    >
                      {transfer.isPending
                        ? "Submitting…"
                        : "Submit transfer request"}
                    </button>
                  </>
                )}
              </div>
            )}

            <div className="panel" id="leave-company">
              <h2>Leave this company</h2>
              {me?.company_role === "owner" || me?.company_needs_ownership_transfer ? (
                <div className="alert" role="status">
                  As the company owner you cannot leave yet. Transfer ownership to another
                  active member first (upload a letter PDF; an administrator must approve).
                  After the transfer, you become a member and can leave normally.
                  {transferCandidates.length === 0 ? (
                    <>
                      {" "}
                      If no other members exist yet, approve a membership request first, then
                      submit the transfer.
                    </>
                  ) : null}
                </div>
              ) : (
                <>
                  <p className="muted">
                    Leaving is personal and immediate — no admin approval. Joining another company
                    still needs that company owner’s approval. Your other company memberships stay.
                  </p>
                  <div className="field">
                    <label htmlFor="detach-note">Note (optional)</label>
                    <textarea
                      id="detach-note"
                      rows={3}
                      value={detachNote}
                      onChange={(e) => setDetachNote(e.target.value)}
                    />
                  </div>
                  {detach.isError && (
                    <div className="alert">
                      {detach.error instanceof Error
                        ? detach.error.message
                        : "Could not leave company"}
                    </div>
                  )}
                  <button
                    type="button"
                    className="btn-primary"
                    disabled={detach.isPending || me?.company_can_detach === false}
                    onClick={() => {
                      if (
                        !window.confirm(
                          "Leave this company now? You can request to join again later.",
                        )
                      ) {
                        return;
                      }
                      void detach.mutateAsync({ note: detachNote }).then(() => {
                        setDetachNote("");
                        void queryClient.invalidateQueries({
                          queryKey: queryKeys.customer.me,
                        });
                      });
                    }}
                  >
                    {detach.isPending ? "Leaving…" : "Leave company"}
                  </button>
                </>
              )}
            </div>
          </div>
        )}
      </div>
    </>
  );
}
