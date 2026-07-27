"use client";

import { useEffect, useMemo, useState } from "react";
import { useQueryClient } from "@tanstack/react-query";
import Link from "next/link";
import { CompanyProfileForm } from "@/components/CompanyProfileForm";
import { CompanyMembersTable } from "@/components/CompanyMembersTable";
import { FaydaIdentityPanel } from "@/components/FaydaIdentityPanel";
import { JoinCompanyPanel } from "@/components/JoinCompanyPanel";
import { PortalPageHeader } from "@/components/PortalPageHeader";
import {
  useCompanyMembers,
  useContact,
  useDetachCompany,
  useTransferOwnership,
} from "@/hooks/use-contact";
import { CompanySwitcher } from "@/components/CompanySwitcher";
import { queryKeys } from "@/lib/query-keys";

type CompanyTab = "identity" | "profile" | "members" | "ownership";
type OrgAction = "create" | "attach" | null;

const HASH_TO_TAB: Record<string, CompanyTab> = {
  "fayda-identity": "identity",
  "fayda-identity-panel": "identity",
  "company-info": "profile",
  "company-profile-panel": "profile",
  "company-members-panel": "members",
  "transfer-ownership": "ownership",
  "leave-company": "ownership",
};

function tabFromHash(): CompanyTab | null {
  if (typeof window === "undefined") return null;
  const hash = window.location.hash.replace(/^#/, "").trim();
  return hash ? (HASH_TO_TAB[hash] ?? null) : null;
}

export default function CompanyProfilePage() {
  const queryClient = useQueryClient();
  const { data: me } = useContact();
  const [orgAction, setOrgAction] = useState<OrgAction>(null);
  const [tab, setTab] = useState<CompanyTab>("identity");
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
  const detach = useDetachCompany();
  const transfer = useTransferOwnership();
  const canViewMembers =
    !membershipDisabled && !!me?.company_id && !pending;
  const showWorkspace = !pending && (isLinked || awaitingApproval);
  const companyMembers = useCompanyMembers(canViewMembers && showWorkspace);
  const [detachNote, setDetachNote] = useState("");
  const [transferTarget, setTransferTarget] = useState("");
  const [transferNote, setTransferNote] = useState("");
  const [transferLetter, setTransferLetter] = useState<File | null>(null);
  const hasMemberships = (me?.memberships?.length ?? 0) > 0;
  const canAddOrganisation = hasMemberships && !membershipDisabled && !pending;

  const tabs = useMemo(() => {
    const items: { id: CompanyTab; label: string }[] = [
      { id: "identity", label: "Fayda identity" },
      { id: "profile", label: "Company profile" },
    ];
    if (canViewMembers) {
      items.push({ id: "members", label: "Members" });
    }
    if (isLinked) {
      items.push({ id: "ownership", label: isOwner ? "Ownership" : "Leave company" });
    }
    return items;
  }, [canViewMembers, isLinked, isOwner]);

  useEffect(() => {
    const applyHash = () => {
      const fromHash = tabFromHash();
      if (fromHash && tabs.some((t) => t.id === fromHash)) {
        setTab(fromHash);
      }
    };
    applyHash();
    window.addEventListener("hashchange", applyHash);
    return () => window.removeEventListener("hashchange", applyHash);
  }, [tabs, me?.public_id, showWorkspace]);

  useEffect(() => {
    if (!tabs.some((t) => t.id === tab)) {
      setTab(tabs[0]?.id ?? "identity");
    }
  }, [tabs, tab]);

  const selectTab = (next: CompanyTab) => {
    setTab(next);
    const hash =
      next === "identity"
        ? "fayda-identity"
        : next === "profile"
          ? "company-info"
          : next === "members"
            ? "company-members-panel"
            : "leave-company";
    if (typeof window !== "undefined") {
      window.history.replaceState(null, "", `#${hash}`);
    }
  };

  const waitingFor =
    pending?.type === "attach"
      ? "company owner"
      : pending?.type === "transfer_ownership"
        ? "admin"
        : "admin";

  const transferCandidates = (companyMembers.data?.members ?? []).filter(
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
            ? "Your access to this company has been disabled. Ask your company owner or an administrator to re-enable it."
            : awaitingApproval
              ? me?.company?.approval_status === "rejected"
                ? `Admin feedback: ${me?.company?.approval_note || "Please complete the required company information and resubmit."}`
                : "You are the company owner. An administrator must approve this unique TIN before you can request VAS services."
              : isLinked
                ? `Manage Fayda identity, company profile, and members for ${me?.company_name || me?.company?.name || "this organisation"} using the tabs below. You can also create your own company or request membership in another organisation.`
                : pending
                  ? `Your ${pending.type} request for ${pending.company?.name || "a company"} is waiting for ${waitingFor} approval. VAS services stay locked until the company TIN is approved.`
                  : `Hello${me?.name ? `, ${me.name.split(" ")[0]}` : ""}. Create a new company with a unique TIN for admin approval, or request to join an existing approved company. You cannot use VAS services until that TIN is approved.`
        }
      />

      <div className="section company-section section-flush">
        {hasMemberships && !membershipDisabled && (
          <div className="panel">
            <h2>Your companies</h2>
            <p className="muted">
              Pick the active company from the list. Subscriptions and service requests use
              that company. You can own some companies and be a member of others.
            </p>
            {me && <CompanySwitcher me={me} variant="page" showHint />}
            {canAddOrganisation && (
              <div className="company-org-actions">
                <button
                  type="button"
                  className={orgAction === "create" ? "btn-primary" : "btn-ghost"}
                  onClick={() =>
                    setOrgAction((v) => (v === "create" ? null : "create"))
                  }
                >
                  {orgAction === "create" ? "Cancel" : "Create my company"}
                </button>
                <button
                  type="button"
                  className={orgAction === "attach" ? "btn-primary" : "btn-ghost"}
                  onClick={() =>
                    setOrgAction((v) => (v === "attach" ? null : "attach"))
                  }
                >
                  {orgAction === "attach" ? "Cancel" : "Request membership"}
                </button>
              </div>
            )}
            {orgAction === "create" && (
              <div style={{ marginTop: "1rem" }}>
                <p className="muted">
                  Register a new company TIN as owner. Your existing memberships stay in
                  place; you can switch after admin approval.
                </p>
                <CompanyProfileForm
                  key="create-another"
                  me={me}
                  createNew
                  redirectTo="/portal/company"
                />
              </div>
            )}
            {orgAction === "attach" && (
              <div style={{ marginTop: "1rem" }}>
                <JoinCompanyPanel
                  embedded
                  title="Request membership in another company"
                  description="Enter an approved company TIN. The owner must approve your join request. You keep membership in your other companies."
                />
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
          <div className="alert" style={{ marginBottom: "1rem" }}>
            Your {pending.type.replaceAll("_", " ")} request
            {pending.company?.name ? ` for ${pending.company.name}` : ""} is waiting for{" "}
            {waitingFor}. Track it under{" "}
            <Link href="/portal/company-requests">
              <strong>Company requests</strong>
            </Link>
            .
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
              <JoinCompanyPanel />
            )}
          </>
        )}

        {showWorkspace && (
          <div className="company-workspace">
            <div
              className="company-workspace-tabs"
              role="tablist"
              aria-label="Company and identity"
            >
              {tabs.map((item) => (
                <button
                  key={item.id}
                  type="button"
                  role="tab"
                  id={`company-tab-${item.id}`}
                  aria-selected={tab === item.id}
                  aria-controls={`company-panel-${item.id}`}
                  className={tab === item.id ? "is-active" : undefined}
                  onClick={() => selectTab(item.id)}
                >
                  {item.label}
                </button>
              ))}
            </div>

            {tab === "identity" && (
              <div
                className="panel"
                role="tabpanel"
                id="company-panel-identity"
                aria-labelledby="company-tab-identity"
              >
                <div id="fayda-identity-panel">
                  <h2>Fayda identity</h2>
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
              </div>
            )}

            {tab === "profile" && (
              <div
                className="panel"
                role="tabpanel"
                id="company-panel-profile"
                aria-labelledby="company-tab-profile"
              >
                <div id="company-profile-panel">
                  <h2>Company profile</h2>
                  {awaitingApproval ? (
                    <>
                      <div className="alert" role="status" style={{ marginBottom: "1rem" }}>
                        VAS services are locked until an administrator approves this company
                        TIN. Each TIN can only be registered once.
                      </div>
                      <p className="muted">
                        {canEditCompany
                          ? "You can update your company details while waiting for admin approval. Resubmitting sends the profile back for review."
                          : "Waiting for admin."}
                      </p>
                      <section id="company-info" className="settings-block">
                        {canEditCompany ? (
                          <CompanyProfileForm
                            key={`${me?.public_id ?? "company"}-pending`}
                            me={me}
                            redirectTo="/portal/company"
                          />
                        ) : (
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
                              <dt>Approval</dt>
                              <dd>{me?.company?.approval_status || "pending"}</dd>
                            </div>
                          </dl>
                        )}
                      </section>
                    </>
                  ) : (
                    <>
                      <p className="muted">
                        Organisation registration for this TIN. After approval, only
                        administrators can change these records.
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
                            <dt>Approval</dt>
                            <dd>{me?.company?.approval_status || "approved"}</dd>
                          </div>
                          <div style={{ gridColumn: "1 / -1" }}>
                            <dt>Address</dt>
                            <dd>{me?.company_address || me?.company?.address || "—"}</dd>
                          </div>
                        </dl>
                      </section>
                    </>
                  )}
                </div>
              </div>
            )}

            {tab === "members" && canViewMembers && (
              <div
                role="tabpanel"
                id="company-panel-members"
                aria-labelledby="company-tab-members"
              >
                <div id="company-members-panel" className="section section-flush">
                  <div className="portal-hero portal-page-header" style={{ paddingBottom: "1rem" }}>
                    <div className="portal-page-header-copy">
                      <h2 style={{ margin: 0 }}>Company members</h2>
                      <p className="muted" style={{ marginBottom: 0 }}>
                        Roster of partners linked to this company. Use Actions on each row
                        {isOwner
                          ? " to enable or disable access and grant permissions."
                          : " to view Fayda identity details."}
                      </p>
                    </div>
                  </div>
                  <CompanyMembersTable enabled={canViewMembers} />
                </div>
              </div>
            )}

            {tab === "ownership" && isLinked && (
              <div
                className="company-ownership-stack"
                role="tabpanel"
                id="company-panel-ownership"
                aria-labelledby="company-tab-ownership"
              >
                {isOwner && (
                  <div className="panel" id="transfer-ownership">
                    <h2>Transfer ownership</h2>
                    <p className="muted">
                      Required before you can leave. Choose an active member as the new
                      owner and upload a signed letter (PDF). An administrator must approve
                      the transfer. Track the transfer under{" "}
                      <Link href="/portal/company-requests">
                        <strong>Company requests</strong>
                      </Link>
                      .
                    </p>
                    {companyMembers.isLoading && (
                      <p className="muted">Loading members…</p>
                    )}
                    {!companyMembers.isLoading && transferCandidates.length === 0 && (
                      <p className="muted" style={{ marginBottom: 0 }}>
                        No other active members yet. Approve a{" "}
                        <Link href="/portal/membership-requests">membership request</Link>{" "}
                        first, then transfer ownership.
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
                            transfer.isPending || !transferTarget || !transferLetter
                          }
                          onClick={() => {
                            if (!transferLetter) return;
                            void transfer
                              .mutateAsync({
                                target_contact: transferTarget,
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
                  {me?.company_role === "owner" ||
                  me?.company_needs_ownership_transfer ? (
                    <div className="alert" role="status">
                      As the company owner you cannot leave yet. Transfer ownership to
                      another active member first (upload a letter PDF; an administrator
                      must approve). After the transfer, you become a member and can leave
                      normally.
                      {transferCandidates.length === 0 ? (
                        <>
                          {" "}
                          If no other members exist yet, approve a membership request first,
                          then submit the transfer.
                        </>
                      ) : null}
                    </div>
                  ) : (
                    <>
                      <p className="muted">
                        Leaving is personal and immediate — no admin approval. Joining
                        another company still needs that company owner’s approval. Your
                        other company memberships stay.
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
                        disabled={
                          detach.isPending || me?.company_can_detach === false
                        }
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
                              queryKey: queryKeys.contact.me,
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
        )}
      </div>
    </>
  );
}
