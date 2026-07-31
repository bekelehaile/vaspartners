"use client";

import Link from "next/link";
import { useMemo, useState } from "react";
import {
  createColumnHelper,
  flexRender,
  getCoreRowModel,
  useReactTable,
} from "@tanstack/react-table";
import { useContact, useServices, useSubscriptions } from "@/hooks/use-contact";
import type { Service, Subscription } from "@/lib/api";
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

/** Termination is only offered when this service has a configured Termination request type. */
function serviceAllowsTermination(services: Service[], sub: Subscription): boolean {
  const serviceId = Number(sub.service?.id ?? sub.service_id ?? 0);
  const svc = services.find((s) => s.id === serviceId);
  if (!svc || svc.is_subscription_based === false) return false;
  return (svc.requisitions ?? []).some((r) => !!r.terminates_subscription);
}

export function SubscriptionsTable() {
  const { data: me } = useContact();
  const { data: services = [] } = useServices();
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
          const canTerminate = serviceAllowsTermination(services, sub);
          return (
            <span className="table-actions">
              <Link
                href={`/portal/requests/new?intent=manage&subscription_id=${sub.id}`}
                className="btn-ghost table-action"
              >
                Manage
              </Link>
              {canTerminate ? (
                <Link
                  href={`/portal/requests/new?intent=manage&subscription_id=${sub.id}&action=terminate`}
                  className="btn-ghost table-action"
                >
                  Terminate
                </Link>
              ) : null}
            </span>
          );
        },
      }),
    ],
    [canManage, services],
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
            <option value="ended">Deactive / ended</option>
          </select>
        </div>
      </div>

      {isError && (
        <div className="alert">
          {error instanceof Error ? error.message : "Unable to load subscriptions"}
        </div>
      )}

      {isLoading ? (
        <p className="portal-mobile-empty">Loading subscriptions…</p>
      ) : filtered.length === 0 ? (
        <p className="portal-mobile-empty">
          {items.length === 0
            ? "No company subscriptions yet. Start a new subscription from Service requests."
            : "No subscriptions match this filter."}
        </p>
      ) : (
        <>
          <ul className="portal-mobile-list">
            {filtered.map((sub) => {
              const alive = isAliveSubscriptionStatus(sub.status);
              const canTerminate =
                alive && canManage && serviceAllowsTermination(services, sub);
              return (
                <li key={sub.id}>
                  <div className="portal-mobile-card">
                    <div className="portal-mobile-card-top">
                      <div>
                        <p className="portal-mobile-card-title">
                          {sub.service?.name ?? sub.public_id}
                        </p>
                        <p className="portal-mobile-card-meta">{sub.public_id}</p>
                      </div>
                      <span className={`status-chip${alive ? " is-alive" : " is-ended"}`}>
                        {subscriptionStatusLabel(sub.status)}
                      </span>
                    </div>
                    <div className="portal-mobile-card-row">
                      <span>Started {formatDate(sub.started_at)}</span>
                      <span>Renewal {formatDate(sub.next_renewal_due_at)}</span>
                    </div>
                    {canManage && alive && (
                      <div className="portal-mobile-card-actions">
                        <Link
                          href={`/portal/requests/new?intent=manage&subscription_id=${sub.id}`}
                          className="btn-ghost table-action"
                        >
                          Manage
                        </Link>
                        {canTerminate ? (
                          <Link
                            href={`/portal/requests/new?intent=manage&subscription_id=${sub.id}&action=terminate`}
                            className="btn-ghost table-action"
                          >
                            Terminate
                          </Link>
                        ) : null}
                      </div>
                    )}
                  </div>
                </li>
              );
            })}
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
            : `${filtered.length} subscription${filtered.length === 1 ? "" : "s"}`}
          {isFetching && !isLoading ? " · Updating…" : ""}
        </p>
      </div>
    </div>
  );
}
