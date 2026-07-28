"use client";

import { useMemo, useState } from "react";
import {
  createColumnHelper,
  flexRender,
  getCoreRowModel,
  useReactTable,
} from "@tanstack/react-table";
import { usePartnerRevenue } from "@/hooks/use-contact";
import type { PartnerRevenueRow } from "@/lib/api";

const columnHelper = createColumnHelper<PartnerRevenueRow>();

function smsLabel(status: string): string {
  switch (status) {
    case "sent":
      return "SMS sent";
    case "failed":
      return "SMS failed";
    case "pending":
      return "SMS pending";
    case "skipped":
      return "SMS skipped";
    case "not_sent":
      return "Not sent";
    default:
      return status;
  }
}

function smsChipClass(status: string): string {
  switch (status) {
    case "sent":
      return "status-chip is-alive";
    case "failed":
      return "status-chip is-ended";
    case "pending":
      return "status-chip";
    default:
      return "status-chip";
  }
}

export function PartnerRevenueTable() {
  const { data, isLoading, isFetching, isError, error } = usePartnerRevenue();
  const [smsFilter, setSmsFilter] = useState<"all" | "failed" | "sent" | "not_sent">("all");

  const items = data?.items ?? [];

  const filtered = useMemo(() => {
    if (smsFilter === "all") return items;
    if (smsFilter === "not_sent") {
      return items.filter((r) => r.sms_status === "not_sent" || r.sms_status === "pending");
    }
    return items.filter((r) => r.sms_status === smsFilter);
  }, [items, smsFilter]);

  const columns = useMemo(
    () => [
      columnHelper.accessor("period", {
        header: "Period",
        cell: (info) => info.getValue() || "—",
      }),
      columnHelper.accessor("service_id", {
        header: "Service ID",
        cell: (info) => <span className="table-mono">{info.getValue() || "—"}</span>,
      }),
      columnHelper.accessor("service_type", {
        header: "Type",
        cell: (info) => info.getValue() || "—",
      }),
      columnHelper.accessor("partner_name", {
        header: "Partner",
        cell: (info) => info.getValue() || "—",
      }),
      columnHelper.accessor("amount_formatted", {
        header: "Amount (ETB)",
        cell: (info) => info.getValue() || "—",
      }),
      columnHelper.accessor("sms_status", {
        header: "SMS",
        cell: (info) => {
          const status = info.getValue();
          const row = info.row.original;
          return (
            <span className={smsChipClass(status)} title={row.sms_error || undefined}>
              {smsLabel(status)}
            </span>
          );
        },
      }),
      columnHelper.accessor("sms_error", {
        header: "SMS detail",
        cell: (info) => {
          const err = info.getValue();
          if (!err) return "—";
          return <span className="table-muted">{err}</span>;
        },
      }),
    ],
    []
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
          <label className="sr-only" htmlFor="revenue-sms-filter">
            SMS status
          </label>
          <select
            id="revenue-sms-filter"
            value={smsFilter}
            onChange={(e) => setSmsFilter(e.target.value as typeof smsFilter)}
          >
            <option value="all">All rows</option>
            <option value="failed">SMS failed</option>
            <option value="sent">SMS sent</option>
            <option value="not_sent">Not sent / pending</option>
          </select>
        </div>
        {isFetching && !isLoading ? <span className="data-table-hint">Refreshing…</span> : null}
      </div>

      {data?.message && items.length === 0 && !isLoading ? (
        <div className="alert">{data.message}</div>
      ) : null}

      {isError && (
        <div className="alert">
          {error instanceof Error ? error.message : "Unable to load revenue"}
        </div>
      )}

      {isLoading ? (
        <p className="portal-mobile-empty">Loading revenue…</p>
      ) : filtered.length === 0 ? (
        <p className="portal-mobile-empty">No revenue rows for this company yet.</p>
      ) : (
        <>
          <ul className="portal-mobile-list">
            {filtered.map((row) => (
              <li key={row.id}>
                <div
                  className={`portal-mobile-card${
                    row.sms_status === "failed" ? " is-attention" : ""
                  }`}
                >
                  <div className="portal-mobile-card-top">
                    <div>
                      <p className="portal-mobile-card-title">
                        {row.amount_formatted || "—"}
                      </p>
                      <p className="portal-mobile-card-meta">
                        {row.period || "—"} · {row.service_type || "—"}
                      </p>
                    </div>
                    <span
                      className={smsChipClass(row.sms_status)}
                      title={row.sms_error || undefined}
                    >
                      {smsLabel(row.sms_status)}
                    </span>
                  </div>
                  <div className="portal-mobile-card-row">
                    <span className="table-mono">{row.service_id || "—"}</span>
                    <span>{row.partner_name || "—"}</span>
                  </div>
                  {row.sms_error && (
                    <p className="portal-mobile-card-meta">{row.sms_error}</p>
                  )}
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
                    <tr
                      key={row.id}
                      className={
                        row.original.sms_status === "failed" ? "is-attention" : undefined
                      }
                    >
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
    </div>
  );
}
