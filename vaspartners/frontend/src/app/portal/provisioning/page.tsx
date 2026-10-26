"use client";

import Link from "next/link";
import { useQuery } from "@tanstack/react-query";
import { PortalPageHeader } from "@/components/PortalPageHeader";
import { getToken, listProvisioning } from "@/lib/api";

export default function ProvisioningListPage() {
  const { data, isLoading, isError, error } = useQuery({
    queryKey: ["provisioning", "list"],
    enabled: !!getToken(),
    queryFn: listProvisioning,
  });

  return (
    <>
      <PortalPageHeader
        title="Provisioning"
        description="Track service provisioning cases (PRV numbers) for your company."
      />

      <div className="section section-flush">
        {isLoading ? (
          <p className="text-sm text-muted-foreground">Loading provisioning cases…</p>
        ) : isError ? (
          <p className="text-sm text-destructive">{(error as Error)?.message || "Failed to load."}</p>
        ) : !data?.length ? (
          <p className="text-sm text-muted-foreground">
            No provisioning cases yet. After contract sign-off, open your subscription and
            start provisioning with a resource allocation request.
          </p>
        ) : (
          <div className="overflow-x-auto rounded-lg border border-border">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-border bg-muted/40 text-left">
                  <th className="px-3 py-2 font-medium">PRV</th>
                  <th className="px-3 py-2 font-medium">Service</th>
                  <th className="px-3 py-2 font-medium">Status</th>
                  <th className="px-3 py-2 font-medium">Overall days</th>
                </tr>
              </thead>
              <tbody>
                {data.map((row) => (
                  <tr key={row.public_id} className="border-b border-border/70">
                    <td className="px-3 py-2">
                      <Link
                        href={`/portal/provisioning/${encodeURIComponent(row.public_id)}`}
                        className="font-medium text-primary hover:underline"
                      >
                        {row.public_id}
                      </Link>
                    </td>
                    <td className="px-3 py-2">{row.service?.name || "—"}</td>
                    <td className="px-3 py-2">{row.status_label || row.status}</td>
                    <td className="px-3 py-2">{row.overall_days ?? 0}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </>
  );
}
