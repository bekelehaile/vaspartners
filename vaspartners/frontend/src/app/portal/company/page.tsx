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
  AdminWorkspace,
  VerticalTabs,
} from "@/components/VerticalTabs";
import {
  useCompanyMembers,
  useContact,
  useDetachCompany,
  useTransferOwnership,
} from "@/hooks/use-contact";
import { CompanySwitcher } from "@/components/CompanySwitcher";
import {
  COMPANY_SECTION_HASH,
  COMPANY_SECTION_LABEL,
  companySectionFromHash,
  type CompanySectionId,
} from "@/lib/portal-company-nav";
import { queryKeys } from "@/lib/query-keys";

type CompanyTab = CompanySectionId;
type OrgAction = "create" | "attach" | null;

function tabFromHash(): CompanyTab | null {
  if (typeof window === "undefined") return null;
  return companySectionFromHash(window.location.hash);
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
      { id: "identity", label: COMPANY_SECTION_LABEL.identity },
      { id: "profile", label: COMPANY_SECTION_LABEL.profile },
    ];
    if (canViewMembers) {
      items.push({ id: "members", label: COMPANY_SECTION_LABEL.members });
    }
    if (isLinked) {
      items.push({
        id: "ownership",
        label: isOwner ? COMPANY_SECTION_LABEL.ownership : "Leave company",
      });
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
    if (typeof window !== "undefined") {
      window.history.replaceState(null, "", `#${COMPANY_SECTION_HASH[next]}`);
      window.dispatchEvent(new HashChangeEvent("hashchange"));
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

  const companyName = me?.company_name || me?.company?.name || "this organisation";

  const workspaceHeader = (() => {
    switch (tab) {
      case "identity":
        return {
          kicker: "Account",
          title: COMPANY_SECTION_LABEL.identity,
          description: `Your personal National ID details from Fayda${
            me?.company_role === "owner" ? " (you are the company owner)" : ""
          }. This is not company registration data.`,
        };
      case "profile":
        return {
          kicker: "Organisation",
          title: COMPANY_SECTION_LABEL.profile,
          description: awaitingApproval
            ? me?.company?.approval_status === "rejected"
              ? `Admin feedback: ${me?.company?.approval_note || "Please complete the required company information and resubmit."}`
              : "An administrator must approve this unique TIN before you can request VAS services."
            : `Organisation registration for ${companyName}. After approval, only administrators can change these records.`,
        };
      case "members":
        return {
          kicker: "Organisation",
          title: COMPANY_SECTION_LABEL.members,
          description: isOwner
            ? `Partners linked to ${companyName}. Use Actions to enable or disable access and grant permissions.`
            : `Partners linked to ${companyName}. Open a row to view Fayda identity details.`,
        };
      case "ownership":
        return {
          kicker: "Organisation",
          title: isOwner ? COMPANY_SECTION_LABEL.ownership : "Leave company",
          description: isOwner
            ? `Transfer ownership of ${companyName} before you leave, or manage your membership.`
            : `Leave ${companyName}. Leaving is personal and immediate — no admin approval.`,
        };
      default:
        return {
          kicker: "Organisation",
          title: COMPANY_SECTION_LABEL.profile,
          description: "",
        };
    }
  })();

  const pageHeader = membershipDisabled
    ? {
        kicker: "Organisation",
        title: "Membership disabled",
        description:
          "Your access to this company has been disabled. Ask your company owner or an administrator to re-enable it.",
      }
    : pending
      ? {
          kicker: "Organisation",
          title: "Company request pending",
          description: `Your ${pending.type} request for ${pending.company?.name || "a company"} is waiting for ${waitingFor} approval. VAS services stay locked until the company TIN is approved.`,
        }
      : showWorkspace
        ? workspaceHeader
        : {
            kicker: "Welcome",
            title: "Link your Fayda account to a company",
            description: `Hello${me?.name ? `, ${me.name.split(" ")[0]}` : ""}. Create a new company with a unique TIN for admin approval, or request to join an existing approved company. You cannot use VAS services until that TIN is approved.`,
          };

  return (
    <>
      <PortalPageHeader
        kicker={pageHeader.kicker}
        title={pageHeader.title}
        description={pageHeader.description}
      />

      <div className="section section-flush">
        <div className="portal-stack">
        {hasMemberships && !membershipDisabled && !showWorkspace && (
          <div className="panel portal-stack">
            <div className="panel-section-head">
              <h2>Your companies</h2>
              <p className="muted">
                Pick the active company from the list. Subscriptions and service requests use
                that company. You can own some companies and be a member of others.
              </p>
            </div>
            {me && <CompanySwitcher me={me} variant="page" showHint />}
          </div>
        )}

        {membershipDisabled && (
          <div className="panel">
            <p className="muted" style={{ margin: 0 }}>
              You remain linked to the company, but you cannot view company details or manage
              company services until an administrator re-enables your access.
            </p>
          </div>
        )}

        {!membershipDisabled && pending && (
          <div className="alert alert-info" role="status">
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
          <AdminWorkspace
            sidebar={
              <VerticalTabs
                label="Company onboarding"
                value={mode}
                onChange={setMode}
                items={[
                  {
                    id: "create",
                    label: "Create new company",
                    description: "Register a unique TIN",
                  },
                  {
                    id: "attach",
                    label: "Attach to existing",
                    description: "Request membership",
                  },
                ]}
              />
            }
          >
            {mode === "create" ? (
              <CompanyProfileForm
                key={`${me?.public_id ?? "company"}-create`}
                me={me}
                redirectTo="/portal/company"
              />
            ) : (
              <JoinCompanyPanel />
            )}
          </AdminWorkspace>
        )}

        {showWorkspace && (
          <AdminWorkspace
            className="company-workspace"
            sidebar={
              <VerticalTabs
                label="Organisation sections"
                value={tab}
                onChange={selectTab}
                items={tabs.map((item) => ({
                  id: item.id,
                  label: item.label,
                }))}
              />
            }
          >
            {tab === "identity" && (
              <div
                className="panel"
                role="tabpanel"
                id="company-panel-identity"
                aria-labelledby="vtab-identity"
              >
                <div id="fayda-identity-panel" className="portal-stack-sm">
                  <FaydaIdentityPanel
                    id="fayda-identity"
                    showHeading={false}
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
                className="portal-stack"
                role="tabpanel"
                id="company-panel-profile"
                aria-labelledby="vtab-profile"
              >
                {hasMemberships && !membershipDisabled && (
                  <div className="panel portal-stack">
                    <div className="panel-section-head">
                      <h2>Your companies</h2>
                      <p className="muted">
                        Pick the active company from the list. Subscriptions and service
                        requests use that company. You can own some companies and be a member
                        of others.
                      </p>
                    </div>
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
                      <div className="portal-stack-sm">
                        <p className="muted">
                          Register a new company with its own unique TIN (for example{" "}
                          <strong>00012345</strong>). Phone and email are copied from your
                          current company. Existing memberships stay in place; switch after
                          admin approval.
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
                      <JoinCompanyPanel
                        embedded
                        title="Request membership in another company"
                        description="Enter an approved company TIN. The owner must approve your join request. You keep membership in your other companies."
                      />
                    )}
                  </div>
                )}

                <div id="company-profile-panel" className="panel portal-stack-sm">
                  {awaitingApproval ? (
                    <>
                      <div className="alert alert-warning" role="status">
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
                        <div className="field-span-full">
                          <dt>Address</dt>
                          <dd>{me?.company_address || me?.company?.address || "—"}</dd>
                        </div>
                      </dl>
                    </section>
                  )}
                </div>
              </div>
            )}

            {tab === "members" && canViewMembers && (
              <div
                role="tabpanel"
                id="company-panel-members"
                aria-labelledby="vtab-members"
              >
                <div id="company-members-panel" className="portal-stack-sm">
                  <CompanyMembersTable enabled={canViewMembers} />
                </div>
              </div>
            )}

            {tab === "ownership" && isLinked && (
              <div
                className="company-ownership-stack"
                role="tabpanel"
                id="company-panel-ownership"
                aria-labelledby="vtab-ownership"
              >
                {isOwner && (
                  <div className="panel" id="transfer-ownership">
                    <div className="panel-section-head">
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
                    </div>
                    {companyMembers.isLoading && (
                      <p className="muted">Loading members…</p>
                    )}
                    {!companyMembers.isLoading && transferCandidates.length === 0 && (
                      <p className="muted">
                        No other active members yet. Approve a{" "}
                        <Link href="/portal/membership-requests">membership request</Link>{" "}
                        first, then transfer ownership.
                      </p>
                    )}
                    {transferCandidates.length > 0 && (
                      <div className="portal-stack-sm">
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
                      </div>
                    )}
                  </div>
                )}

                <div className="panel" id="leave-company">
                  <div className="panel-section-head">
                    <h2>Leave this company</h2>
                  </div>
                  {me?.company_role === "owner" ||
                  me?.company_needs_ownership_transfer ? (
                    <div className="alert alert-warning" role="status">
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
                    <div className="portal-stack-sm">
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
                        className="btn-danger"
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
                    </div>
                  )}
                </div>
              </div>
            )}
          </AdminWorkspace>
        )}
        </div>
      </div>
    </>
  );
}
