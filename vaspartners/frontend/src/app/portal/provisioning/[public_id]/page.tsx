"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useState } from "react";
import { PortalPageHeader } from "@/components/PortalPageHeader";
import { getToken } from "@/lib/auth-token";
import { getProvisioning, submitProvisioningStep } from "@/lib/api";

export default function ProvisioningDetailPage() {
  const params = useParams<{ public_id: string | string[] }>();
  const raw = params.public_id;
  const publicId = decodeURIComponent(Array.isArray(raw) ? raw[0] ?? "" : raw ?? "");
  const [remark, setRemark] = useState("");
  const qc = useQueryClient();

  const { data, isLoading, isError, error } = useQuery({
    queryKey: ["provisioning", publicId],
    enabled: !!getToken() && !!publicId,
    queryFn: () => getProvisioning(publicId),
  });

  const act = useMutation({
    mutationFn: () =>
      submitProvisioningStep(publicId, data!.can_act_step!, { remark: remark || undefined }),
    onSuccess: () => {
      setRemark("");
      qc.invalidateQueries({ queryKey: ["provisioning", publicId] });
      qc.invalidateQueries({ queryKey: ["provisioning", "list"] });
    },
  });

  return (
    <>
      <PortalPageHeader
        title={data?.public_id || "Provisioning"}
        description={
          data
            ? `${data.service?.name || "Service"} · ${data.status_label || data.status} · ${data.overall_days ?? 0} day(s) overall`
            : "Provisioning case details"
        }
        actions={
          <Link href="/portal/provisioning" className="btn-ghost">
            Back to provisioning
          </Link>
        }
      />

      <div className="section section-flush space-y-6">
        {isLoading ? (
          <p className="text-sm text-muted-foreground">Loading…</p>
        ) : isError ? (
          <p className="text-sm text-destructive">{(error as Error)?.message || "Failed to load."}</p>
        ) : data ? (
          <>
            {data.credentials ? (
              <div className="rounded-lg border border-border p-4">
                <h2 className="mb-2 text-base font-semibold">Shared credentials</h2>
                <dl className="grid gap-2 text-sm sm:grid-cols-2">
                  {Object.entries(data.credentials).map(([k, v]) => (
                    <div key={k}>
                      <dt className="text-muted-foreground">{k.replaceAll("_", " ")}</dt>
                      <dd className="font-medium break-all">{String(v)}</dd>
                    </div>
                  ))}
                </dl>
              </div>
            ) : null}

            {data.can_act_step ? (
              <div className="rounded-lg border border-border p-4 space-y-3">
                <h2 className="text-base font-semibold">Your action</h2>
                <p className="text-sm text-muted-foreground">
                  Confirm step <span className="font-medium">{data.can_act_step}</span>
                </p>
                <textarea
                  className="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                  rows={3}
                  placeholder="Remark (optional)"
                  value={remark}
                  onChange={(e) => setRemark(e.target.value)}
                />
                <button
                  type="button"
                  className="btn-primary"
                  disabled={act.isPending}
                  onClick={() => act.mutate()}
                >
                  {act.isPending ? "Submitting…" : "Confirm"}
                </button>
                {act.isError ? (
                  <p className="text-sm text-destructive">
                    {(act.error as Error)?.message || "Could not submit."}
                  </p>
                ) : null}
              </div>
            ) : null}

            <div className="rounded-lg border border-border overflow-x-auto">
              <h2 className="border-b border-border px-4 py-3 text-base font-semibold">
                Follow-up status
              </h2>
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-border bg-muted/40 text-left">
                    <th className="px-3 py-2">What</th>
                    <th className="px-3 py-2">Where</th>
                    <th className="px-3 py-2">Status</th>
                    <th className="px-3 py-2">Days</th>
                    <th className="px-3 py-2">Created</th>
                  </tr>
                </thead>
                <tbody>
                  {(data.follow_up || []).map((row) => (
                    <tr key={row.step_key} className="border-b border-border/70">
                      <td className="px-3 py-2">{row.what}</td>
                      <td className="px-3 py-2">{row.where}</td>
                      <td className="px-3 py-2">{row.status_label || row.status}</td>
                      <td className="px-3 py-2">{row.days_in_step ?? 0}</td>
                      <td className="px-3 py-2">
                        {row.created_at ? new Date(row.created_at).toLocaleString() : "—"}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </>
        ) : null}
      </div>
    </>
  );
}
