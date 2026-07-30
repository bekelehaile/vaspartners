"use client";

import { useState } from "react";
import { useQueryClient } from "@tanstack/react-query";
import {
  useAttachCompany,
  useLookupCompany,
} from "@/hooks/use-contact";
import { queryKeys } from "@/lib/query-keys";
import { isValidEthiopianTin, normalizeEthiopianTin } from "@/lib/tin";

/** Request to join a company by TIN. */
export function JoinCompanyPanel({
  title = "Join a company",
  description = "Enter the company TIN to request access.",
  embedded = false,
}: {
  title?: string;
  description?: string;
  embedded?: boolean;
}) {
  const queryClient = useQueryClient();
  const [tin, setTin] = useState("");
  const [note, setNote] = useState("");
  const [lookupTin, setLookupTin] = useState("");
  const lookup = useLookupCompany(lookupTin);
  const attach = useAttachCompany();
  const tinOk = isValidEthiopianTin(tin);

  const body = (
    <>
      <h2 style={{ marginTop: 0 }}>{title}</h2>
      <p className="muted">{description}</p>
      <div className="field">
        <label htmlFor="attach-tin">
          TIN <span aria-hidden="true">*</span>
        </label>
        <input
          id="attach-tin"
          value={tin}
          onChange={(e) => setTin(e.target.value.replace(/[^\d\s-]/g, ""))}
          placeholder="10 digits"
          inputMode="numeric"
          maxLength={14}
          required
          aria-required="true"
        />
      </div>
      <div className="field">
        <label htmlFor="attach-note">Note (optional)</label>
        <textarea
          id="attach-note"
          rows={2}
          value={note}
          onChange={(e) => setNote(e.target.value)}
          placeholder="Optional message"
        />
      </div>
      <div style={{ display: "flex", gap: "0.75rem", flexWrap: "wrap" }}>
        <button
          type="button"
          className="btn-ghost"
          disabled={!tinOk || lookup.isFetching}
          onClick={() => {
            setLookupTin(normalizeEthiopianTin(tin));
          }}
        >
          {lookup.isFetching ? "Searching…" : "Find company"}
        </button>
        <button
          type="button"
          className="btn-primary"
          disabled={attach.isPending || !tinOk}
          onClick={() =>
            void attach
              .mutateAsync({
                company_tin: normalizeEthiopianTin(tin),
                note,
              })
              .then(() => {
                void queryClient.invalidateQueries({
                  queryKey: queryKeys.contact.me,
                });
              })
          }
        >
          {attach.isPending ? "Sending…" : "Request to join"}
        </button>
      </div>
      {lookupTin && lookup.data && (
        <p style={{ marginTop: "1rem" }}>
          Found: <strong>{lookup.data.name}</strong>
          {lookup.data.tin ? ` · ${lookup.data.tin}` : ""}
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
            : "Could not send request"}
        </div>
      )}
    </>
  );

  if (embedded) {
    return <div className="join-company-embedded">{body}</div>;
  }

  return <div className="panel">{body}</div>;
}
