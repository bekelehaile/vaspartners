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

function displayPhone(row: PartnerRevenueRow): string {
  return row.sms_phone_display || row.sms_phone || row.phone || "—";
}

export function PartnerRevenueTable() {
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(15);
  const [smsFilter, setSmsFilter] = useState<"all" | "failed" | "sent" | "not_sent">("all");

  const { data, isLoading, isFetching, isError, error } = usePartnerRevenue({
    page,
    per_page: perPage,
    sms_status: smsFilter,
  });

  const items = data?.items ?? [];
  const total = data?.total ?? 0;
  const currentPage = data?.currentPage ?? page;
  const lastPage = data?.lastPage ?? 1;

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
      columnHelper.accessor("sms_phone_display", {
        header: "SMS phone",
        cell: (info) => (
          <span className="table-mono">{displayPhone(info.row.original)}</span>
        ),
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
    data: items,
    columns,
    getCoreRowModel: getCoreRowModel(),
    manualPagination: true,
    pageCount: lastPage,
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
            onChange={(e) => {
              setSmsFilter(e.target.value as typeof smsFilter);
              setPage(1);
            }}
          >
            <option value="all">All rows</option>
            <option value="failed">SMS failed</option>
            <option value="sent">SMS sent</option>
            <option value="not_sent">Not sent / pending</option>
          </select>

          <label className="sr-only" htmlFor="revenue-per-page">
            Rows per page
          </label>
          <select
            id="revenue-per-page"
            value={perPage}
            onChange={(e) => {
              setPerPage(Number(e.target.value));
              setPage(1);
            }}
          >
            <option value={10}>10 / page</option>
            <option value={15}>15 / page</option>
            <option value={25}>25 / page</option>
            <option value={50}>50 / page</option>
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
      ) : items.length === 0 ? (
        <p className="portal-mobile-empty">No revenue rows for this company yet.</p>
      ) : (
        <>
          <ul className="portal-mobile-list">
            {items.map((row) => (
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
                  <div className="portal-mobile-card-row">
                    <span className="portal-mobile-card-meta">SMS phone</span>
                    <span className="table-mono">{displayPhone(row)}</span>
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

      {!isLoading && total > 0 ? (
        <div className="data-table-footer">
          <p className="muted">
            {`Page ${currentPage} of ${lastPage} · ${total} row${total === 1 ? "" : "s"}`}
            {isFetching && !isLoading ? " · Updating…" : ""}
          </p>
          <div className="data-table-pager">
            <button
              type="button"
              className="btn-secondary"
              disabled={currentPage <= 1 || isFetching}
              onClick={() => setPage((p) => Math.max(1, p - 1))}
            >
              Previous
            </button>
            <button
              type="button"
              className="btn-secondary"
              disabled={currentPage >= lastPage || isFetching}
              onClick={() => setPage((p) => p + 1)}
            >
              Next
            </button>
          </div>
        </div>
      ) : null}
    </div>
  );
}
