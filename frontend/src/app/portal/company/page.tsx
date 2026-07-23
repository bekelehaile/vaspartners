"use client";

import { useState } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { CompanyProfileForm } from "@/components/CompanyProfileForm";
import { PortalPageHeader } from "@/components/PortalPageHeader";
import {
  useAttachCompany,
  useCustomer,
  useDecideMembershipRequest,
  useDetachCompany,
  useLookupCompany,
  useMembershipRequests,
  useSwitchCompany,
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
  const membershipRequests = useMembershipRequests(!!isOwner && isLinked);
  const decideMembership = useDecideMembershipRequest();
  const [proposal, setProposal] = useState<File | null>(null);
  const [letter, setLetter] = useState<File | null>(null);
  const [detachNote, setDetachNote] = useState("");

  const waitingFor =
    pending?.type === "attach" ? "company owner" : "admin";

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
                ? "Company membership"
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
                : "You are the company owner. An administrator must verify that all required company information is complete before you can use VAS services."
              : isLinked
                ? `You are linked to ${me?.company_name || me?.company?.name || "your organisation"} as ${me?.company_role || "member"}. Company details are managed by admin after approval.`
                : pending
                  ? `Your ${pending.type} request for ${pending.company?.name || "a company"} is waiting for ${waitingFor} approval.`
                  : `Hello${me?.name ? `, ${me.name.split(" ")[0]}` : ""}. Create a new company (unique TIN + license) for admin approval, or request to join an existing approved company.`
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
            {pending.customer_note && (
              <p className="muted">Your note: {pending.customer_note}</p>
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
                  company owner must approve your membership.
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
          <div className="portal-grid">
            {isOwner && (
              <div className="panel" style={{ gridColumn: "1 / -1" }}>
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

            <div className="panel">
              <h2>Organisation details</h2>
              <p className="muted">
                After approval, only administrators can update or change company records.
              </p>
              <dl className="journey-summary">
                <div>
                  <dt>Name</dt>
                  <dd>{me?.company_name || me?.company?.name || "—"}</dd>
                </div>
                <div>
                  <dt>TIN</dt>
                  <dd>{me?.company_tin || me?.company?.tin || "—"}</dd>
                </div>
                <div>
                  <dt>License</dt>
                  <dd>
                    {me?.company_license_number || me?.company?.license_number || "—"}
                  </dd>
                </div>
                <div>
                  <dt>Phone</dt>
                  <dd>{me?.company_phone || me?.company?.phone || "—"}</dd>
                </div>
                <div>
                  <dt>Email</dt>
                  <dd>{me?.company_email || me?.company?.email || "—"}</dd>
                </div>
                <div style={{ gridColumn: "1 / -1" }}>
                  <dt>Address</dt>
                  <dd>{me?.company_address || me?.company?.address || "—"}</dd>
                </div>
                <div>
                  <dt>Your role</dt>
                  <dd>{me?.company_role || "member"}</dd>
                </div>
                <div>
                  <dt>Approval</dt>
                  <dd>{me?.company?.approval_status || "approved"}</dd>
                </div>
              </dl>
            </div>
            <div className="panel">
              <h2>Request detach / move</h2>
              {me?.company_needs_ownership_transfer ? (
                <div className="alert" role="status">
                  You are the company owner and other members are still linked. Ownership must be
                  transferred in the admin portal before you can detach.
                </div>
              ) : (
                <>
                  <p className="muted">
                    To leave this company (and later join another), submit a proposal PDF and a
                    request letter PDF. Admin will approve or reject; you will get SMS and portal
                    notification.
                  </p>
                  <div className="field">
                    <label htmlFor="detach-note">Reason (optional)</label>
                    <textarea
                      id="detach-note"
                      rows={3}
                      value={detachNote}
                      onChange={(e) => setDetachNote(e.target.value)}
                    />
                  </div>
                  <div className="field">
                    <label htmlFor="detach-proposal">Proposal PDF *</label>
                    <input
                      id="detach-proposal"
                      type="file"
                      accept="application/pdf,.pdf"
                      onChange={(e) => setProposal(e.target.files?.[0] || null)}
                    />
                  </div>
                  <div className="field">
                    <label htmlFor="detach-letter">Request letter PDF *</label>
                    <input
                      id="detach-letter"
                      type="file"
                      accept="application/pdf,.pdf"
                      onChange={(e) => setLetter(e.target.files?.[0] || null)}
                    />
                  </div>
                  {detach.isError && (
                    <div className="alert">
                      {detach.error instanceof Error
                        ? detach.error.message
                        : "Could not submit detach request"}
                    </div>
                  )}
                  <button
                    type="button"
                    className="btn-primary"
                    disabled={
                      detach.isPending ||
                      !proposal ||
                      !letter ||
                      me?.company_can_detach === false
                    }
                    onClick={() => {
                      if (!proposal || !letter) return;
                      void detach
                        .mutateAsync({ note: detachNote, proposal, letter })
                        .then(() => {
                          setProposal(null);
                          setLetter(null);
                          setDetachNote("");
                          void queryClient.invalidateQueries({
                            queryKey: queryKeys.customer.me,
                          });
                        });
                    }}
                  >
                    {detach.isPending ? "Submitting…" : "Submit detach request"}
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
