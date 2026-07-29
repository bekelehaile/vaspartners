"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { FormEvent, useMemo, useState } from "react";
import {
  createColumnHelper,
  flexRender,
  getCoreRowModel,
  useReactTable,
} from "@tanstack/react-table";
import { FileTextIcon, SearchIcon } from "lucide-react";
import { JourneyLaunchActions } from "@/components/PortalPageHeader";
import { StatusPill } from "@/components/StatusJourney";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardFooter } from "@/components/ui/card";
import {
  Empty,
  EmptyContent,
  EmptyDescription,
  EmptyHeader,
  EmptyMedia,
  EmptyTitle,
} from "@/components/ui/empty";
import { Input } from "@/components/ui/input";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { useServices, useTickets, useDeleteRejectedTicket, type TicketFilters } from "@/hooks/use-contact";
import { statusCopy, type Ticket } from "@/lib/api";
import { cn } from "@/lib/utils";

const columnHelper = createColumnHelper<Ticket>();

const STATUS_OPTIONS: { value: string; label: string }[] = [
  { value: "", label: "All statuses" },
  { value: "open", label: statusCopy.open.label },
  { value: "in_progress", label: statusCopy.in_progress.label },
  { value: "completed", label: statusCopy.completed.label },
  { value: "closed", label: statusCopy.closed.label },
  { value: "rejected", label: statusCopy.rejected.label },
];

function formatSubmitted(value?: string | null) {
  if (!value) return "—";
  return new Date(value).toLocaleDateString(undefined, {
    year: "numeric",
    month: "short",
    day: "numeric",
  });
}

export function RequestsTable({
  initialPerPage = 25,
  compact = false,
}: {
  initialPerPage?: number;
  compact?: boolean;
}) {
  const router = useRouter();
  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("");
  const [serviceId, setServiceId] = useState("");
  const [page, setPage] = useState(1);
  const deleteRejected = useDeleteRejectedTicket();

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

  const onDeleteRequest = (ticket: Ticket) => {
    const open = ticket.status === "open";
    const ok = window.confirm(
      open
        ? `Permanently delete request ${ticket.tt_number}? Ethio telecom has not started handling it yet. This removes the request and uploaded documents. This cannot be undone.`
        : `Permanently delete rejected request ${ticket.tt_number}? This removes the request, messages, and all uploaded documents. This cannot be undone.`,
    );
    if (!ok) return;
    void deleteRejected.mutateAsync(ticket.tt_number).catch(() => undefined);
  };

  const columns = useMemo(
    () => [
      columnHelper.accessor("tt_number", {
        header: "Request",
        cell: (info) => {
          const ticket = info.row.original;
          return (
            <div className="flex min-w-0 flex-col gap-0.5">
              <Link
                href={`/portal/requests/${ticket.tt_number}`}
                className="font-semibold text-foreground hover:text-primary"
                onClick={(e) => e.stopPropagation()}
              >
                {info.getValue()}
              </Link>
              {ticket.requisition?.name && (
                <span className="text-xs text-muted-foreground">
                  {ticket.requisition.name}
                </span>
              )}
            </div>
          );
        },
      }),
      columnHelper.accessor((row) => row.service?.name ?? "—", {
        id: "service",
        header: "Service",
        cell: (info) => (
          <div className="flex min-w-0 flex-col gap-0.5">
            <span className="font-medium text-foreground">{info.getValue()}</span>
            {info.row.original.contact?.name && (
              <span className="text-xs text-muted-foreground">
                by {info.row.original.contact.name}
              </span>
            )}
          </div>
        ),
      }),
      columnHelper.accessor("status", {
        header: "Status",
        cell: (info) => <StatusPill status={info.getValue()} />,
      }),
      columnHelper.accessor((row) => row.assignee?.name ?? null, {
        id: "assignee",
        header: "Handled by",
        cell: (info) => {
          const name = info.getValue();
          return (
            <span className={name ? "text-foreground" : "text-muted-foreground"}>
              {name || "Awaiting assignment"}
            </span>
          );
        },
      }),
      columnHelper.accessor("created_at", {
        header: "Submitted",
        cell: (info) => (
          <span className="text-muted-foreground">
            {formatSubmitted(info.getValue())}
          </span>
        ),
      }),
      columnHelper.display({
        id: "actions",
        header: "",
        cell: (info) => {
          const ticket = info.row.original;
          const canDelete = ticket.can_delete === true;
          const canEdit =
            ticket.contact_can_edit === true ||
            ticket.status === "open" ||
            ticket.status === "rejected";
          return (
            <div className="flex flex-wrap items-center gap-2">
              <Button
                variant="outline"
                size="sm"
                className="h-7"
                render={<Link href={`/portal/requests/${ticket.tt_number}`} />}
                onClick={(e) => e.stopPropagation()}
              >
                View
              </Button>
              {canEdit && (
                <Button
                  variant="outline"
                  size="sm"
                  className="h-7"
                  render={
                    <Link href={`/portal/requests/${ticket.tt_number}?edit=1`} />
                  }
                  onClick={(e) => e.stopPropagation()}
                >
                  Edit
                </Button>
              )}
              {canDelete && (
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  className="h-8 min-h-8 border-destructive/40 text-destructive hover:bg-destructive/10 sm:h-7"
                  disabled={deleteRejected.isPending}
                  onClick={(e) => {
                    e.stopPropagation();
                    onDeleteRequest(ticket);
                  }}
                >
                  Delete
                </Button>
              )}
            </div>
          );
        },
      }),
    ],
    [deleteRejected.isPending],
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
  const selectClass =
    "h-8 w-full min-w-0 flex-1 rounded-lg border border-input bg-transparent px-2.5 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 sm:min-w-[9.5rem] sm:flex-none";

  return (
    <Card className={cn("gap-0 py-0 shadow-sm", compact && "is-compact")}>
      {!compact && (
        <div className="flex flex-col gap-3 border-b px-4 py-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
          <form className="flex w-full min-w-0 flex-1 gap-2 sm:min-w-[min(100%,18rem)]" onSubmit={applySearch}>
            <label className="sr-only" htmlFor="requests-search">
              Search requests
            </label>
            <div className="relative flex-1">
              <SearchIcon className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
              <Input
                id="requests-search"
                value={searchInput}
                onChange={(e) => setSearchInput(e.target.value)}
                placeholder="Search request number, service, or notes"
                className="pl-8"
              />
            </div>
            <Button type="submit" variant="outline" size="sm" className="h-8 shrink-0">
              Search
            </Button>
          </form>

          <div className="flex w-full flex-wrap items-center gap-2 sm:w-auto">
            <label className="sr-only" htmlFor="requests-status">
              Status
            </label>
            <select
              id="requests-status"
              className={selectClass}
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
              className={selectClass}
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
              <Button type="button" variant="link" size="sm" onClick={clearFilters}>
                Clear
              </Button>
            )}
          </div>
        </div>
      )}

      {isError && (
        <div className="alert mx-4 my-3">
          {error instanceof Error ? error.message : "Unable to load requests"}
        </div>
      )}

      {deleteRejected.isError && (
        <div className="alert mx-4 my-3" role="alert">
          {deleteRejected.error instanceof Error
            ? deleteRejected.error.message
            : "Could not delete rejected request"}
        </div>
      )}

      <CardContent className="px-0">
        {isLoading ? (
          <Empty className="border-0 py-12">
            <EmptyHeader>
              <EmptyTitle>Loading requests…</EmptyTitle>
            </EmptyHeader>
          </Empty>
        ) : items.length === 0 ? (
          <Empty className="border-0 py-12">
            <EmptyHeader>
              <EmptyMedia variant="icon">
                <FileTextIcon />
              </EmptyMedia>
              <EmptyTitle>
                {hasFilters ? "No matching requests" : "No service requests yet"}
              </EmptyTitle>
              <EmptyDescription>
                {hasFilters
                  ? "Try a different status, service, or clear your filters."
                  : "Start a subscription or manage an existing service for this company."}
              </EmptyDescription>
            </EmptyHeader>
            <EmptyContent>
              {!hasFilters && !compact ? (
                <JourneyLaunchActions />
              ) : hasFilters ? (
                <Button type="button" variant="outline" size="sm" onClick={clearFilters}>
                  Clear filters
                </Button>
              ) : null}
            </EmptyContent>
          </Empty>
        ) : (
          <>
            <ul className="portal-mobile-list">
              {items.map((ticket) => (
                <li key={ticket.public_id || ticket.tt_number}>
                  <div className="portal-mobile-card">
                    <div className="portal-mobile-card-hit">
                      <div className="portal-mobile-card-top">
                        <div>
                          <p className="portal-mobile-card-title">{ticket.tt_number}</p>
                          {ticket.requisition?.name && (
                            <p className="portal-mobile-card-meta">{ticket.requisition.name}</p>
                          )}
                        </div>
                        <StatusPill status={ticket.status} />
                      </div>
                      <div className="portal-mobile-card-row">
                        <span>{ticket.service?.name ?? "—"}</span>
                        <span>{formatSubmitted(ticket.created_at)}</span>
                      </div>
                      <p className="portal-mobile-card-meta">
                        Handled by{" "}
                        {ticket.assignee?.name
                          ? ticket.assignee.name
                          : "Awaiting assignment"}
                      </p>
                      {ticket.contact?.name && (
                        <p className="portal-mobile-card-meta">by {ticket.contact.name}</p>
                      )}
                    </div>
                    <div className="portal-mobile-card-actions">
                      <Button
                        type="button"
                        variant="outline"
                        className="min-h-11"
                        onClick={() =>
                          router.push(`/portal/requests/${ticket.tt_number}`)
                        }
                      >
                        View
                      </Button>
                      {(ticket.contact_can_edit === true ||
                        ticket.status === "open" ||
                        ticket.status === "rejected") && (
                        <Button
                          type="button"
                          variant="outline"
                          className="min-h-11"
                          onClick={() =>
                            router.push(
                              `/portal/requests/${ticket.tt_number}?edit=1`,
                            )
                          }
                        >
                          Edit
                        </Button>
                      )}
                      {ticket.can_delete === true && (
                        <Button
                          type="button"
                          variant="outline"
                          className="border-destructive/40 text-destructive min-h-11"
                          disabled={deleteRejected.isPending}
                          onClick={() => onDeleteRequest(ticket)}
                        >
                          Delete
                        </Button>
                      )}
                    </div>
                  </div>
                </li>
              ))}
            </ul>

            <div className="portal-desktop-table">
              <Table className="min-w-[640px]">
                <TableHeader>
                  {table.getHeaderGroups().map((hg) => (
                    <tr key={hg.id} className="border-b bg-muted/40 hover:bg-muted/40">
                      {hg.headers.map((header) => (
                        <TableHead
                          key={header.id}
                          className="h-10 px-4 text-[0.72rem] font-bold tracking-[0.06em] text-muted-foreground uppercase"
                        >
                          {header.isPlaceholder
                            ? null
                            : flexRender(header.column.columnDef.header, header.getContext())}
                        </TableHead>
                      ))}
                    </tr>
                  ))}
                </TableHeader>
                <TableBody>
                  {table.getRowModel().rows.map((row) => (
                    <TableRow key={row.id}>
                      {row.getVisibleCells().map((cell) => (
                        <TableCell key={cell.id} className="px-4 py-3 whitespace-normal">
                          {flexRender(cell.column.columnDef.cell, cell.getContext())}
                        </TableCell>
                      ))}
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>
          </>
        )}
      </CardContent>

      {!compact && items.length > 0 && total >= 10 && (
        <CardFooter className="flex-col items-stretch justify-between gap-3 border-t bg-muted/30 py-3 sm:flex-row sm:items-center">
          <p className="text-sm text-muted-foreground">
            {`Page ${currentPage} of ${lastPage} · ${total} request${total === 1 ? "" : "s"}`}
            {isFetching && !isLoading ? " · Updating…" : ""}
          </p>
          <div className="flex gap-2">
            <Button
              type="button"
              variant="outline"
              size="sm"
              className="flex-1 sm:flex-none"
              disabled={currentPage <= 1}
              onClick={() => setPage((p) => Math.max(1, p - 1))}
            >
              Previous
            </Button>
            <Button
              type="button"
              variant="outline"
              size="sm"
              className="flex-1 sm:flex-none"
              disabled={currentPage >= lastPage}
              onClick={() => setPage((p) => p + 1)}
            >
              Next
            </Button>
          </div>
        </CardFooter>
      )}

      {compact && total > items.length && (
        <CardFooter className="border-t bg-muted/30 py-3">
          <Button variant="link" size="sm" render={<Link href="/portal" />}>
            View all {total} requests →
          </Button>
        </CardFooter>
      )}
    </Card>
  );
}
