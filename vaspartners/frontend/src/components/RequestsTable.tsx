"use client";

import Link from "next/link";
import { FormEvent, useMemo, useState } from "react";
import {
  createColumnHelper,
  flexRender,
  getCoreRowModel,
  useReactTable,
} from "@tanstack/react-table";
import { StatusPill } from "@/components/StatusJourney";
import { useServices, useTickets, type TicketFilters } from "@/hooks/use-customer";
import { statusCopy, type Ticket } from "@/lib/api";

const columnHelper = createColumnHelper<Ticket>();

const STATUS_OPTIONS: { value: string; label: string }[] = [
  { value: "", label: "All statuses" },
  { value: "open", label: statusCopy.open.label },
  { value: "in_progress", label: statusCopy.in_progress.label },
  { value: "completed", label: statusCopy.completed.label },
  { value: "closed", label: statusCopy.closed.label },
  { value: "rejected", label: statusCopy.rejected.label },
];

export function RequestsTable({
  initialPerPage = 15,
  compact = false,
}: {
  initialPerPage?: number;
  compact?: boolean;
}) {
  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("");
  const [serviceId, setServiceId] = useState("");
  const [page, setPage] = useState(1);

  const filters: TicketFilters = {
    search,
    status,
    service_id: serviceId,
    page,
    per_page: compact ? 5 : initialPerPage,
  };

  const { data: services = [] } = useServices();
  const { data, isLoading, isFetching, isError, error } = useTickets(filters);

  const items = data?.items ?? [];
  const total = data?.total ?? 0;
  const lastPage = data?.lastPage ?? 1;
  const currentPage = data?.currentPage ?? 1;

  const columns = useMemo(
    () => [
      columnHelper.accessor("tt_number", {
        header: "Request #",
        cell: (info) => (
          <Link href={`/portal/requests/${info.row.original.public_id}`} className="table-link">
            {info.getValue()}
          </Link>
        ),
      }),
      columnHelper.accessor((row) => row.service?.name ?? "—", {
        id: "service",
        header: "Service",
      }),
      columnHelper.accessor((row) => row.requisition?.name ?? "—", {
        id: "requisition",
        header: "Request type",
      }),
      columnHelper.accessor("status", {
        header: "Status",
        cell: (info) => <StatusPill status={info.getValue()} />,
      }),
      columnHelper.accessor("created_at", {
        header: "Submitted",
        cell: (info) =>
          info.getValue()
            ? new Date(info.getValue()).toLocaleDateString(undefined, {
                year: "numeric",
                month: "short",
                day: "numeric",
              })
            : "—",
      }),
      columnHelper.display({
        id: "actions",
        header: "",
        cell: (info) => (
          <Link
            href={`/portal/requests/${info.row.original.public_id}`}
            className="btn-ghost table-action"
          >
            View
          </Link>
        ),
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

  const applySearch = (e: FormEvent) => {
    e.preventDefault();
    setPage(1);
    setSearch(searchInput.trim());
  };

  const clearFilters = () => {
    setSearchInput("");
    setSearch("");
    setStatus("");
    setServiceId("");
    setPage(1);
  };

  const hasFilters = !!(search || status || serviceId);

  return (
    <div className={`data-table-card${compact ? " is-compact" : ""}`}>
      {!compact && (
        <div className="data-table-toolbar">
          <form className="data-table-search" onSubmit={applySearch}>
            <label className="sr-only" htmlFor="requests-search">
              Search requests
            </label>
            <input
              id="requests-search"
              value={searchInput}
              onChange={(e) => setSearchInput(e.target.value)}
              placeholder="Search by TT number, service, or notes…"
            />
            <button type="submit" className="btn-ghost">
              Search
            </button>
          </form>

          <div className="data-table-filters">
            <label className="sr-only" htmlFor="requests-status">
              Status
            </label>
            <select
              id="requests-status"
              value={status}
              onChange={(e) => {
                setStatus(e.target.value);
                setPage(1);
              }}
            >
              {STATUS_OPTIONS.map((o) => (
                <option key={o.value || "all"} value={o.value}>
                  {o.label}
                </option>
              ))}
            </select>

            <label className="sr-only" htmlFor="requests-service">
              Service
            </label>
            <select
              id="requests-service"
              value={serviceId}
              onChange={(e) => {
                setServiceId(e.target.value);
                setPage(1);
              }}
            >
              <option value="">All services</option>
              {services.map((s) => (
                <option key={s.id} value={s.id}>
                  {s.name}
                </option>
              ))}
            </select>

            {hasFilters && (
              <button type="button" className="linkish" onClick={clearFilters}>
                Clear filters
              </button>
            )}
          </div>
        </div>
      )}

      {isError && (
        <div className="alert">
          {error instanceof Error ? error.message : "Unable to load requests"}
        </div>
      )}

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
            {isLoading ? (
              <tr>
                <td colSpan={columns.length} className="data-table-empty">
                  Loading requests…
                </td>
              </tr>
            ) : items.length === 0 ? (
              <tr>
                <td colSpan={columns.length} className="data-table-empty">
                  {hasFilters
                    ? "No requests match your filters."
                    : "No requests yet. Use New subscription or Manage service above to get started."}
                </td>
              </tr>
            ) : (
              table.getRowModel().rows.map((row) => (
                <tr key={row.id}>
                  {row.getVisibleCells().map((cell) => (
                    <td key={cell.id}>
                      {flexRender(cell.column.columnDef.cell, cell.getContext())}
                    </td>
                  ))}
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>

      {!compact && (
        <div className="data-table-footer">
          <p className="muted">
            {total === 0
              ? "0 results"
              : `Showing page ${currentPage} of ${lastPage} · ${total} request${total === 1 ? "" : "s"}`}
            {isFetching && !isLoading ? " · Updating…" : ""}
          </p>
          <div className="data-table-pager">
            <button
              type="button"
              className="btn-ghost"
              disabled={currentPage <= 1}
              onClick={() => setPage((p) => Math.max(1, p - 1))}
            >
              Previous
            </button>
            <button
              type="button"
              className="btn-ghost"
              disabled={currentPage >= lastPage}
              onClick={() => setPage((p) => p + 1)}
            >
              Next
            </button>
          </div>
        </div>
      )}

      {compact && total > items.length && (
        <div className="data-table-footer">
          <Link href="/portal" className="linkish">
            View all {total} requests →
          </Link>
        </div>
      )}
    </div>
  );
}
