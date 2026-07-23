"use client";

import { useState } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { CompanyProfileForm } from "@/components/CompanyProfileForm";
import { PortalPageHeader } from "@/components/PortalPageHeader";
import {
  useAttachCompany,
  useCustomer,
  useDetachCompany,
  useLookupCompany,
} from "@/hooks/use-customer";
import { queryKeys } from "@/lib/query-keys";

export default function CompanyProfilePage() {
  const queryClient = useQueryClient();
  const { data: me } = useCustomer();
  const isLinked = !!me?.profile_completed && !!me?.company_id;
  const pending = me?.pending_company_request;
  const [mode, setMode] = useState<"create" | "attach">("create");
  const [tin, setTin] = useState("");
  const [note, setNote] = useState("");
  const [lookupTin, setLookupTin] = useState("");
  const lookup = useLookupCompany(lookupTin);
  const attach = useAttachCompany();
  const detach = useDetachCompany();
  const [proposal, setProposal] = useState<File | null>(null);
  const [letter, setLetter] = useState<File | null>(null);
  const [detachNote, setDetachNote] = useState("");

  return (
    <>
      <PortalPageHeader
        kicker={isLinked ? "Settings" : "Welcome"}
        title={
          isLinked
            ? "Company membership"
            : pending
              ? "Company request pending"
              : "Link your Fayda account to a company"
        }
        description={
          isLinked
            ? `You are linked to ${me?.company_name || me?.company?.name || "your organisation"} as ${me?.company_role || "member"}.`
            : pending
              ? `Your ${pending.type} request for ${pending.company?.name || "a company"} is waiting for admin approval.`
              : `Hello${me?.name ? `, ${me.name.split(" ")[0]}` : ""}. Create a new company or request to join an existing one (TIN). Attach requests need admin approval.`
        }
      />

      <div className="section company-section section-flush">
        {pending && (
          <div className="panel">
            <h2>Waiting for admin decision</h2>
            <p className="muted">
              Type: <strong>{pending.type}</strong> · Status:{" "}
              <strong>{pending.status}</strong>
            </p>
            {pending.company && (
              <p>
                {pending.company.name} · TIN {pending.company.tin}
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

        {!pending && !isLinked && (
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
                redirectTo="/portal"
              />
            ) : (
              <div className="panel">
                <h2>Join an existing company</h2>
                <p className="muted">
                  Enter the company TIN. An admin must approve before you can submit VAS
                  requests under that company.
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
                  <label htmlFor="attach-note">Note to admin (optional)</label>
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
                    disabled={tin.trim().length < 5 || lookup.isFetching}
                    onClick={() => setLookupTin(tin.trim())}
                  >
                    {lookup.isFetching ? "Looking up…" : "Lookup TIN"}
                  </button>
                  <button
                    type="button"
                    className="btn-primary"
                    disabled={attach.isPending || tin.trim().length < 5}
                    onClick={() =>
                      void attach.mutateAsync({ company_tin: tin.trim(), note }).then(() => {
                        void queryClient.invalidateQueries({ queryKey: queryKeys.customer.me });
                      })
                    }
                  >
                    {attach.isPending ? "Submitting…" : "Request attach"}
                  </button>
                </div>
                {lookupTin && lookup.data && (
                  <p style={{ marginTop: "1rem" }}>
                    Found: <strong>{lookup.data.name}</strong> (TIN {lookup.data.tin})
                  </p>
                )}
                {lookupTin && lookup.isError && (
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
                      : "Could not submit attach request"}
                  </div>
                )}
              </div>
            )}
          </>
        )}

        {!pending && isLinked && (
          <div className="portal-grid">
            <div className="panel">
              <h2>Organisation details</h2>
              {me?.company_role === "owner" ? (
                <CompanyProfileForm
                  key={`${me?.public_id ?? "company"}-edit`}
                  me={me}
                  redirectTo="/portal/company"
                />
              ) : (
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
                </dl>
              )}
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
                    disabled={detach.isPending || !proposal || !letter || me?.company_can_detach === false}
                    onClick={() => {
                      if (!proposal || !letter) return;
                      void detach
                        .mutateAsync({ note: detachNote, proposal, letter })
                        .then(() => {
                          setProposal(null);
                          setLetter(null);
                          setDetachNote("");
                          void queryClient.invalidateQueries({ queryKey: queryKeys.customer.me });
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
