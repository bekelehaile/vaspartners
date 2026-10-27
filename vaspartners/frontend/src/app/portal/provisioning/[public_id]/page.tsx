"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useMemo, useState } from "react";
import { PortalPageHeader } from "@/components/PortalPageHeader";
import { ProvisioningChatPanel } from "@/components/ProvisioningChatPanel";
import { getToken, getProvisioning, submitProvisioningStep } from "@/lib/api";

function EyeIcon({ slash }: { slash?: boolean }) {
  if (slash) {
    return (
      <svg viewBox="0 0 24 24" className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden>
        <path d="M3 3l18 18" />
        <path d="M10.6 10.6a2 2 0 0 0 2.8 2.8" />
        <path d="M9.9 5.1A10.5 10.5 0 0 1 12 5c7 0 10 7 10 7a13.3 13.3 0 0 1-3.3 3.9" />
        <path d="M6.1 6.1A13.4 13.4 0 0 0 2 12s3 7 10 7a9.8 9.8 0 0 0 4.4-1" />
      </svg>
    );
  }
  return (
    <svg viewBox="0 0 24 24" className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden>
      <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z" />
      <circle cx="12" cy="12" r="3" />
    </svg>
  );
}

export default function ProvisioningDetailPage() {
  const params = useParams<{ public_id: string | string[] }>();
  const raw = params.public_id;
  const publicId = decodeURIComponent(Array.isArray(raw) ? raw[0] ?? "" : raw ?? "");
  const [remark, setRemark] = useState("");
  const [fields, setFields] = useState<Record<string, string>>({});
  const [attachment, setAttachment] = useState<File | null>(null);
  const [revealedSecrets, setRevealedSecrets] = useState<Record<string, boolean>>({});
  const qc = useQueryClient();

  const isSecretCredentialKey = (key: string) => {
    const k = key.toLowerCase();
    return k.includes("password") || k.includes("secret") || k === "smsc_password";
  };

  const { data, isLoading, isError, error } = useQuery({
    queryKey: ["provisioning", publicId],
    enabled: !!getToken() && !!publicId,
    queryFn: () => getProvisioning(publicId),
  });

  const requiredMissing = useMemo(() => {
    if (!data?.can_act_fields?.length) return false;
    return data.can_act_fields.some(
      (f) => f.required && !String(fields[f.name] ?? "").trim(),
    );
  }, [data?.can_act_fields, fields]);

  const attachmentMissing = Boolean(data?.requires_attachment && !attachment);

  const act = useMutation({
    mutationFn: () => {
      const payload: Record<string, string | undefined> & { attachment?: File } = {
        remark: remark.trim(),
      };
      for (const f of data?.can_act_fields ?? []) {
        const v = String(fields[f.name] ?? "").trim();
        if (v) payload[f.name] = v;
      }
      if (attachment) payload.attachment = attachment;
      return submitProvisioningStep(publicId, data!.can_act_step!, payload);
    },
    onSuccess: () => {
      setRemark("");
      setFields({});
      setAttachment(null);
      qc.invalidateQueries({ queryKey: ["provisioning", publicId] });
      qc.invalidateQueries({ queryKey: ["provisioning", "list"] });
      qc.invalidateQueries({ queryKey: ["provisioning", publicId, "messages"] });
    },
  });

  const manageHref = data?.subscription_public_id
    ? `/portal/subscriptions/${encodeURIComponent(data.subscription_public_id)}`
    : "/portal/subscriptions";

  const isTerminal =
    data?.status === "go_live" || data?.status === "cancelled" || data?.status === "rejected";

  return (
    <>
      <PortalPageHeader
        title={data?.public_id || "Service activation"}
        description={
          data
            ? `${data.service?.name || "Service"} · ${data.status_label || data.status} · ${data.overall_days ?? 0} day(s) overall`
            : "Service activation details"
        }
        actions={
          <div className="flex flex-wrap gap-2">
            {data?.chat_locked || isTerminal ? (
              <Link href={manageHref} className="btn-primary">
                Manage subscription
              </Link>
            ) : null}
            <Link href="/portal/provisioning" className="btn-ghost">
              Back to service activation
            </Link>
          </div>
        }
      />

      <div className="section section-flush space-y-6">
        {isLoading ? (
          <p className="text-sm text-muted-foreground">Loading…</p>
        ) : isError ? (
          <p className="text-sm text-destructive">{(error as Error)?.message || "Failed to load."}</p>
        ) : data ? (
          <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)]">
            <ProvisioningChatPanel
              publicId={publicId}
              chatLocked={!!data.chat_locked}
              chatBlockReason={data.chat_block_reason}
              maxKb={data.chat_attachment_max_kb ?? 2048}
              initialMessages={data.messages ?? []}
              subscriptionPublicId={data.subscription_public_id}
            />

            <div className="space-y-6">
              {data.status === "go_live" ? (
                <div className="rounded-lg border border-border p-4 space-y-2">
                  <h2 className="text-base font-semibold">Completed</h2>
                  <p className="text-sm text-muted-foreground">
                    Service activation is finished. Messaging is closed. Use Subscription Manage for
                    renewals and further changes.
                  </p>
                  <Link href={manageHref} className="btn-primary inline-flex">
                    Manage subscription
                  </Link>
                </div>
              ) : null}

              {data.status === "cancelled" ? (
                <div className="rounded-lg border border-border p-4 space-y-2">
                  <h2 className="text-base font-semibold">Cancelled</h2>
                  <p className="text-sm text-muted-foreground">
                    This activation was cancelled. Messaging is closed.
                  </p>
                </div>
              ) : null}

              {data.chat_locked && data.status === "in_progress" ? (
                <div className="rounded-lg border border-border p-4 space-y-2">
                  <h2 className="text-base font-semibold">Configuration submitted</h2>
                  <p className="text-sm text-muted-foreground">
                    Your completion was recorded. Support messaging is closed while Ethio telecom
                    continues the remaining steps.
                  </p>
                </div>
              ) : null}

              {data.credentials ? (
                <div className="rounded-lg border border-border p-4">
                  <h2 className="mb-2 text-base font-semibold">Shared credentials</h2>
                  <dl className="grid gap-3 text-sm sm:grid-cols-2">
                    {Object.entries(data.credentials).map(([k, v]) => {
                      const secret = isSecretCredentialKey(k);
                      const shown = !!revealedSecrets[k];
                      const text = String(v ?? "");
                      return (
                        <div key={k} className="rounded-md bg-muted/40 px-3 py-2">
                          <dt className="text-muted-foreground">{k.replaceAll("_", " ")}</dt>
                          <dd className="mt-1 flex items-start gap-2 font-medium">
                            <code className="min-w-0 flex-1 break-all text-sm">
                              {secret && !shown ? "••••••••••••" : text || "—"}
                            </code>
                            {secret ? (
                              <button
                                type="button"
                                className="btn-ghost shrink-0 px-2 py-1"
                                aria-label={shown ? "Hide password" : "Show password"}
                                title={shown ? "Hide" : "Show"}
                                onClick={() =>
                                  setRevealedSecrets((prev) => ({ ...prev, [k]: !prev[k] }))
                                }
                              >
                                <EyeIcon slash={shown} />
                              </button>
                            ) : null}
                          </dd>
                        </div>
                      );
                    })}
                  </dl>
                </div>
              ) : null}

              {data.can_act_step ? (
                <div className="rounded-lg border border-border p-4 space-y-3">
                  <h2 className="text-base font-semibold">
                    {data.can_act_submit_label || data.can_act_label || "Your action"}
                  </h2>
                  <p className="text-sm text-muted-foreground">
                    Choose status, complete any fields, then submit. After Completed or Cancelled,
                    support messaging closes.
                  </p>
                  <div className="grid gap-3 sm:grid-cols-2">
                    {(data.can_act_fields ?? []).map((f) => (
                      <label
                        key={f.name}
                        className={`block text-sm${f.type === "textarea" || f.type === "file" ? " sm:col-span-2" : ""}`}
                      >
                        <span className="mb-1 block font-medium">
                          {f.label}
                          {f.required ? " *" : ""}
                        </span>
                        {f.type === "textarea" ? (
                          <textarea
                            className="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                            rows={3}
                            value={fields[f.name] ?? ""}
                            onChange={(e) =>
                              setFields((prev) => ({ ...prev, [f.name]: e.target.value }))
                            }
                            required={!!f.required}
                          />
                        ) : f.type === "select" ? (
                          <select
                            className="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                            value={fields[f.name] ?? ""}
                            onChange={(e) =>
                              setFields((prev) => ({ ...prev, [f.name]: e.target.value }))
                            }
                            required={!!f.required}
                          >
                            <option value="">Select…</option>
                            {(f.options ?? []).map((opt) => (
                              <option key={opt.value} value={opt.value}>
                                {opt.label}
                              </option>
                            ))}
                          </select>
                        ) : (
                          <input
                            className="w-full rounded-md border border-border bg-background px-3 py-2"
                            type={f.type === "url" ? "url" : f.type === "password" ? "password" : "text"}
                            value={fields[f.name] ?? ""}
                            onChange={(e) =>
                              setFields((prev) => ({ ...prev, [f.name]: e.target.value }))
                            }
                            placeholder={f.type === "url" ? "https://" : undefined}
                            required={!!f.required}
                          />
                        )}
                      </label>
                    ))}
                    <label className="block text-sm sm:col-span-2">
                      <span className="mb-1 block font-medium">Remark *</span>
                      <textarea
                        className="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                        rows={3}
                        value={remark}
                        onChange={(e) => setRemark(e.target.value)}
                        required
                      />
                    </label>
                    {data.requires_attachment || data.attachment_label ? (
                      <label className="block text-sm sm:col-span-2">
                        <span className="mb-1 block font-medium">
                          {data.attachment_label || "Attachment"}
                          {data.requires_attachment ? " *" : ""}
                        </span>
                        <input
                          className="w-full rounded-md border border-border bg-background px-3 py-2"
                          type="file"
                          accept={(data.attachment_accepted_mimes || "pdf,doc,docx,png,jpg,jpeg")
                            .split(",")
                            .map((e) => `.${e.trim()}`)
                            .join(",")}
                          onChange={(e) => setAttachment(e.target.files?.[0] ?? null)}
                          required={!!data.requires_attachment}
                        />
                        <span className="mt-1 block text-xs text-muted-foreground">
                          Allowed: {data.attachment_accepted_mimes || "pdf,doc,docx,png,jpg,jpeg"}
                          {data.attachment_max_kb ? ` · max ${data.attachment_max_kb} KB` : ""}. Validated
                          on the server.
                        </span>
                      </label>
                    ) : null}
                  </div>
                  <button
                    type="button"
                    className="btn-primary"
                    disabled={
                      act.isPending || requiredMissing || attachmentMissing || !remark.trim()
                    }
                    onClick={() => act.mutate()}
                  >
                    {act.isPending
                      ? "Submitting…"
                      : data.can_act_submit_label || data.can_act_label || "Submit"}
                  </button>
                  {act.isError ? (
                    <p className="text-sm text-destructive">
                      {(act.error as Error)?.message || "Could not submit."}
                    </p>
                  ) : null}
                </div>
              ) : null}

              <div className="rounded-lg border border-border p-4 space-y-3">
                <h2 className="text-base font-semibold">Progress</h2>
                <p className="text-sm text-muted-foreground">
                  {!Array.isArray(data.follow_up) && data.follow_up?.headline
                    ? data.follow_up.headline
                    : "Limited updates for your activation. Internal Ethio telecom steps are not shown here."}
                </p>
                {!Array.isArray(data.follow_up) && data.follow_up?.awaiting_ethio_telecom ? (
                  <p className="text-sm font-medium text-foreground">
                    Currently with Ethio telecom — no action needed from you right now.
                  </p>
                ) : null}
                <ul className="space-y-2 text-sm">
                  {(Array.isArray(data.follow_up)
                    ? data.follow_up
                    : data.follow_up?.items ?? []
                  ).map((row) => (
                    <li
                      key={row.step_key}
                      className="flex flex-wrap items-center justify-between gap-2 border-b border-border/60 pb-2 last:border-0 last:pb-0"
                    >
                      <span>
                        {row.what}
                        {"needs_you" in row && row.needs_you ? (
                          <span className="ml-2 text-xs font-semibold text-amber-700">Needs you</span>
                        ) : null}
                      </span>
                      <span className="status-chip is-alive">{row.status_label || row.status || "—"}</span>
                    </li>
                  ))}
                </ul>
              </div>
            </div>
          </div>
        ) : null}
      </div>
    </>
  );
}
