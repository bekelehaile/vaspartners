"use client";

import Link from "next/link";
import { useMemo, useState } from "react";
import {
  createColumnHelper,
  flexRender,
  getCoreRowModel,
  useReactTable,
} from "@tanstack/react-table";
import { useContact, useSubscriptions } from "@/hooks/use-contact";
import type { Subscription } from "@/lib/api";
import {
  contactCanManageServices,
  isAliveSubscriptionStatus,
  subscriptionStatusLabel,
} from "@/lib/company-permissions";

const columnHelper = createColumnHelper<Subscription>();

function formatDate(value?: string | null): string {
  if (!value) return "—";
  return new Date(value).toLocaleDateString(undefined, {
    year: "numeric",
    month: "short",
    day: "numeric",
  });
}

export function SubscriptionsTable() {
  const { data: me } = useContact();
  const { data, isLoading, isFetching, isError, error } = useSubscriptions();
  const [statusFilter, setStatusFilter] = useState<"all" | "alive" | "ended">("all");

  const canManage = contactCanManageServices(me);
  const items = data?.items ?? [];

  const filtered = useMemo(() => {
    if (statusFilter === "alive") {
      return items.filter((s) => isAliveSubscriptionStatus(s.status));
    }
    if (statusFilter === "ended") {
      return items.filter((s) => !isAliveSubscriptionStatus(s.status));
    }
    return items;
  }, [items, statusFilter]);

  const columns = useMemo(
    () => [
      columnHelper.accessor("public_id", {
        header: "Subscription",
        cell: (info) => <span className="table-link">{info.getValue()}</span>,
      }),
      columnHelper.accessor((row) => row.service?.name ?? "—", {
        id: "service",
        header: "Service",
      }),
      columnHelper.accessor("status", {
        header: "Status",
        cell: (info) => {
          const alive = isAliveSubscriptionStatus(info.getValue());
          return (
            <span className={`status-chip${alive ? " is-alive" : " is-ended"}`}>
              {subscriptionStatusLabel(info.getValue())}
            </span>
          );
        },
      }),
      columnHelper.accessor("started_at", {
        header: "Started",
        cell: (info) => formatDate(info.getValue()),
      }),
      columnHelper.accessor("current_period_end", {
        header: "Period end",
        cell: (info) => formatDate(info.getValue()),
      }),
      columnHelper.accessor("next_renewal_due_at", {
        header: "Next renewal",
        cell: (info) => formatDate(info.getValue()),
      }),
      columnHelper.display({
        id: "actions",
        header: "",
        cell: (info) => {
          const sub = info.row.original;
          if (!canManage || !isAliveSubscriptionStatus(sub.status)) {
            return null;
          }
          return (
            <Link
              href={`/portal/requests/new?intent=manage&subscription_id=${sub.id}`}
              className="btn-ghost table-action"
            >
              Manage
            </Link>
          );
        },
      }),
    ],
    [canManage]
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
          <label className="sr-only" htmlFor="subscriptions-status">
            Status
          </label>
          <select
            id="subscriptions-status"
            value={statusFilter}
            onChange={(e) => setStatusFilter(e.target.value as typeof statusFilter)}
          >
            <option value="all">All subscriptions</option>
            <option value="alive">Active / renewing</option>
            <option value="ended">Ended</option>
          </select>
        </div>
      </div>

      {isError && (
        <div className="alert">
          {error instanceof Error ? error.message : "Unable to load subscriptions"}
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
                  Loading subscriptions…
                </td>
              </tr>
            ) : filtered.length === 0 ? (
              <tr>
                <td colSpan={columns.length} className="data-table-empty">
                  {items.length === 0
                    ? "No company subscriptions yet. Start a new subscription from Service requests."
                    : "No subscriptions match this filter."}
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

      <div className="data-table-footer">
        <p className="muted">
          {filtered.length === 0
            ? "0 results"
            : `${filtered.length} subscription${filtered.length === 1 ? "" : "s"}`}
          {isFetching && !isLoading ? " · Updating…" : ""}
        </p>
      </div>
    </div>
  );
}
