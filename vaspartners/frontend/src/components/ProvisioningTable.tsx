"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  createColumnHelper,
  flexRender,
  getCoreRowModel,
  useReactTable,
} from "@tanstack/react-table";
import { useMemo, useState } from "react";
import { useContact } from "@/hooks/use-contact";
import { contactCanManageProvisioning } from "@/lib/company-permissions";
import {
  getToken,
  listProvisioning,
  startProvisioning,
  type ProvisioningCase,
  type ProvisioningStartable,
} from "@/lib/api";

type RowKind = "ready" | "case";

type ProvisioningRow = {
  key: string;
  kind: RowKind;
  reference: string;
  serviceName: string;
  status: string;
  statusLabel: string;
  overallDays: number | null;
  contractSignedAt: string | null;
  startable?: ProvisioningStartable;
  caseRow?: ProvisioningCase;
};

const columnHelper = createColumnHelper<ProvisioningRow>();

function formatDate(value?: string | null): string {
  if (!value) return "—";
  return new Date(value).toLocaleDateString(undefined, {
    year: "numeric",
    month: "short",
    day: "numeric",
  });
}

export function ProvisioningTable() {
  const router = useRouter();
  const qc = useQueryClient();
  const { data: me } = useContact();
  const canProvision = contactCanManageProvisioning(me);

  const { data, isLoading, isFetching, isError, error } = useQuery({
    queryKey: ["provisioning", "list"],
    enabled: !!getToken(),
    queryFn: listProvisioning,
  });

  const [statusFilter, setStatusFilter] = useState<"all" | "ready" | "open" | "done">("all");
  const [search, setSearch] = useState("");
  const [activating, setActivating] = useState<ProvisioningStartable | null>(null);

  const rows = useMemo(() => {
    const out: ProvisioningRow[] = [];

    if (canProvision) {
      for (const s of data?.startable ?? []) {
        out.push({
          key: `ready-${s.public_id}`,
          kind: "ready",
          reference: s.public_id,
          serviceName: s.service?.name || "—",
          status: "ready",
          statusLabel: "Ready to start",
          overallDays: null,
          contractSignedAt: s.contract_signed_at ?? null,
          startable: s,
        });
      }
    }

    for (const c of data?.data ?? []) {
      out.push({
        key: `case-${c.public_id}`,
        kind: "case",
        reference: c.public_id,
        serviceName: c.service?.name || "—",
        status: String(c.status || "").toLowerCase(),
        statusLabel: c.status_label || c.status || "—",
        overallDays: c.overall_days ?? 0,
        contractSignedAt: null,
        caseRow: c,
      });
    }

    return out;
  }, [canProvision, data?.data, data?.startable]);

  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase();
    return rows.filter((row) => {
      if (statusFilter === "ready" && row.kind !== "ready") return false;
      if (
        statusFilter === "open" &&
        !(row.kind === "case" && ["draft", "in_progress", "blocked"].includes(row.status))
      ) {
        return false;
      }
      if (statusFilter === "done" && !(row.kind === "case" && row.status === "completed")) return false;
      if (!q) return true;
      return (
        row.reference.toLowerCase().includes(q) ||
        row.serviceName.toLowerCase().includes(q) ||
        row.statusLabel.toLowerCase().includes(q)
      );
    });
  }, [rows, search, statusFilter]);

  const columns = useMemo(
    () => [
      columnHelper.accessor("reference", {
        header: "Reference",
        cell: (info) => {
          const row = info.row.original;
          if (row.kind === "case") {
            return (
              <Link
                href={`/portal/provisioning/${encodeURIComponent(row.reference)}`}
                className="table-link"
              >
                {row.reference}
              </Link>
            );
          }
          return <span className="font-mono text-xs">{row.reference}</span>;
        },
      }),
      columnHelper.accessor("serviceName", { header: "Service" }),
      columnHelper.accessor("statusLabel", {
        header: "Status",
        cell: (info) => {
          const row = info.row.original;
          const ended = row.status === "completed" || row.status === "cancelled";
          return (
            <span className={`status-chip${ended ? " is-ended" : " is-alive"}`}>
              {info.getValue()}
            </span>
          );
        },
      }),
      columnHelper.accessor("overallDays", {
        header: "Days",
        cell: (info) => (info.getValue() == null ? "—" : String(info.getValue())),
      }),
      columnHelper.display({
        id: "actions",
        header: "",
        cell: (info) => {
          const row = info.row.original;
          return (
            <span className="table-actions">
              {row.kind === "case" && row.caseRow?.can_act_step ? (
                <Link
                  href={`/portal/provisioning/${encodeURIComponent(row.reference)}`}
                  className="btn-primary table-action"
                >
                  {row.caseRow.can_act_submit_label || "Continue"}
                </Link>
              ) : row.kind === "case" ? (
                <Link
                  href={`/portal/provisioning/${encodeURIComponent(row.reference)}`}
                  className="btn-ghost table-action"
                >
                  View
                </Link>
              ) : null}
              {row.kind === "ready" && canProvision ? (
                <button
                  type="button"
                  className="btn-primary table-action"
                  onClick={() => setActivating(row.startable ?? null)}
                >
                  Submit to VAS Sales
                </button>
              ) : null}
            </span>
          );
        },
      }),
    ],
    [canProvision],
  );

  const table = useReactTable({
    data: filtered,
    columns,
    getCoreRowModel: getCoreRowModel(),
  });

  return (
    <div className="data-table-card">
      <div className="data-table-toolbar">
        <div className="data-table-filters">
          <label className="sr-only" htmlFor="provisioning-status">
            Status
          </label>
          <select
            id="provisioning-status"
            value={statusFilter}
            onChange={(e) => setStatusFilter(e.target.value as typeof statusFilter)}
          >
            <option value="all">All</option>
            <option value="ready">Ready to start</option>
            <option value="open">In progress</option>
            <option value="done">Completed</option>
          </select>
          <label className="sr-only" htmlFor="provisioning-search">
            Search
          </label>
          <input
            id="provisioning-search"
            type="search"
            placeholder="Search…"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          />
        </div>
      </div>

      {isError ? (
        <div className="alert">
          {error instanceof Error ? error.message : "Unable to load service activations"}
        </div>
      ) : null}

      {activating ? (
        <ActivationRequestForm
          subscription={activating}
          acceptedMimes={data?.attachment_accepted_mimes}
          maxKb={data?.attachment_max_kb}
          onCancel={() => setActivating(null)}
          onSuccess={(publicId) => {
            setActivating(null);
            qc.invalidateQueries({ queryKey: ["provisioning", "list"] });
            router.push(`/portal/provisioning/${encodeURIComponent(publicId)}`);
          }}
        />
      ) : null}

      {isLoading ? (
        <p className="portal-mobile-empty">Loading…</p>
      ) : filtered.length === 0 ? (
        <p className="portal-mobile-empty">
          {rows.length === 0
            ? "Nothing here yet. After contract sign-off, eligible services appear as Ready to start."
            : "No rows match this filter."}
        </p>
      ) : (
        <>
          <ul className="portal-mobile-list">
            {filtered.map((row) => (
              <li key={row.key}>
                <div className="portal-mobile-card">
                  <div className="portal-mobile-card-top">
                    <div>
                      <p className="portal-mobile-card-title">{row.serviceName}</p>
                      <p className="portal-mobile-card-meta">{row.reference}</p>
                    </div>
                    <span
                      className={`status-chip${
                        row.status === "completed" || row.status === "cancelled"
                          ? " is-ended"
                          : " is-alive"
                      }`}
                    >
                      {row.statusLabel}
                    </span>
                  </div>
                  <div className="portal-mobile-card-row">
                    <span>
                      {row.kind === "ready"
                        ? `Contract ${formatDate(row.contractSignedAt)}`
                        : `${row.overallDays ?? 0} day(s)`}
                    </span>
                  </div>
                  <div className="portal-mobile-card-actions">
                    {row.kind === "case" && row.caseRow?.can_act_step ? (
                      <Link
                        href={`/portal/provisioning/${encodeURIComponent(row.reference)}`}
                        className="btn-primary table-action"
                      >
                        {row.caseRow.can_act_submit_label || "Continue"}
                      </Link>
                    ) : row.kind === "case" ? (
                      <Link
                        href={`/portal/provisioning/${encodeURIComponent(row.reference)}`}
                        className="btn-ghost table-action"
                      >
                        View
                      </Link>
                    ) : null}
                    {row.kind === "ready" && canProvision ? (
                      <button
                        type="button"
                        className="btn-primary table-action"
                        onClick={() => setActivating(row.startable ?? null)}
                      >
                        Submit to VAS Sales
                      </button>
                    ) : null}
                  </div>
                </div>
              </li>
            ))}
          </ul>

          <div className="portal-desktop-table">
            <div className="data-table-wrap">
              <table className="data-table">
                <thead>
                  {table.getHeaderGroups().map((hg) => (
                    <tr key={hg.id}>
                      {hg.headers.map((header) => (
                        <th key={header.id}>
                          {header.isPlaceholder
                            ? null
                            : flexRender(header.column.columnDef.header, header.getContext())}
                        </th>
                      ))}
                    </tr>
                  ))}
                </thead>
                <tbody>
                  {table.getRowModel().rows.map((row) => (
                    <tr key={row.id}>
                      {row.getVisibleCells().map((cell) => (
                        <td key={cell.id}>
                          {flexRender(cell.column.columnDef.cell, cell.getContext())}
                        </td>
                      ))}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        </>
      )}

      <div className="data-table-footer">
        <p className="muted">
          {filtered.length === 0
            ? "0 results"
            : `${filtered.length} row${filtered.length === 1 ? "" : "s"}`}
          {isFetching && !isLoading ? " · Updating…" : ""}
        </p>
      </div>
    </div>
  );
}

function ActivationRequestForm({
  subscription,
  acceptedMimes,
  maxKb,
  onCancel,
  onSuccess,
}: {
  subscription: ProvisioningStartable;
  acceptedMimes?: string;
  maxKb?: number;
  onCancel: () => void;
  onSuccess: (publicId: string) => void;
}) {
  const [shortCode, setShortCode] = useState("");
  const [dataSyncUrl, setDataSyncUrl] = useState("");
  const [letter, setLetter] = useState<File | null>(null);
  const [remark, setRemark] = useState("");

  const accept = useMemo(
    () =>
      (acceptedMimes || "pdf,doc,docx,png,jpg,jpeg")
        .split(",")
        .map((e) => `.${e.trim()}`)
        .join(","),
    [acceptedMimes],
  );

  const mutate = useMutation({
    mutationFn: () => {
      if (!letter) throw new Error("Service activation letter is required.");
      return startProvisioning({
        subscription_public_id: subscription.public_id,
        short_code: shortCode.trim(),
        data_sync_url: dataSyncUrl.trim() || undefined,
        remark: remark.trim(),
        attachment: letter,
      });
    },
    onSuccess: (created) => onSuccess(created.public_id),
  });

  return (
    <div className="mb-4 rounded-lg border border-border p-4 space-y-3">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div>
          <h3 className="text-sm font-semibold">
            Service activation request — {subscription.service?.name || "Service"}
          </h3>
          <p className="text-xs text-muted-foreground font-mono">{subscription.public_id}</p>
        </div>
        <button type="button" className="btn-ghost" onClick={onCancel}>
          Cancel
        </button>
      </div>

      <div className="grid gap-3 sm:grid-cols-2">
        <label className="block text-sm">
          <span className="mb-1 block font-medium">
            Short code <span className="req" aria-hidden="true">*</span>
          </span>
          <input
            className="w-full rounded-md border border-border bg-background px-3 py-2"
            value={shortCode}
            onChange={(e) => setShortCode(e.target.value)}
            placeholder="e.g. 8080"
            required
          />
        </label>
        <label className="block text-sm">
          <span className="mb-1 block font-medium">Data Sync URL (optional)</span>
          <input
            className="w-full rounded-md border border-border bg-background px-3 py-2"
            value={dataSyncUrl}
            onChange={(e) => setDataSyncUrl(e.target.value)}
            placeholder="https://"
          />
        </label>
        <label className="block text-sm sm:col-span-2">
          <span className="mb-1 block font-medium">
            Remark <span className="req" aria-hidden="true">*</span>
          </span>
          <textarea
            className="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
            rows={2}
            value={remark}
            onChange={(e) => setRemark(e.target.value)}
            required
          />
        </label>
        <label className="block text-sm sm:col-span-2">
          <span className="mb-1 block font-medium">
            Service activation letter <span className="req" aria-hidden="true">*</span>
          </span>
          <input
            className="w-full rounded-md border border-border bg-background px-3 py-2"
            type="file"
            accept={accept}
            onChange={(e) => setLetter(e.target.files?.[0] ?? null)}
            required
          />
          <span className="mt-1 block text-xs text-muted-foreground">
            {acceptedMimes || "pdf,doc,docx,png,jpg,jpeg"}
            {maxKb ? ` · max ${maxKb} KB` : ""}
          </span>
        </label>
      </div>

      {mutate.isError ? (
        <p className="text-sm text-destructive">
          {(mutate.error as Error)?.message || "Could not submit."}
        </p>
      ) : null}

      <button
        type="button"
        className="btn-primary"
        disabled={mutate.isPending || !shortCode.trim() || !letter || !remark.trim()}
        onClick={() => mutate.mutate()}
      >
        {mutate.isPending ? "Submitting…" : "Submit to VAS Sales"}
      </button>
    </div>
  );
}
