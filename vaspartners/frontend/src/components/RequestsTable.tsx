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
import { useServices, useTickets, type TicketFilters } from "@/hooks/use-contact";
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
  initialPerPage = 15,
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
        cell: (info) => (
          <Button
            variant="outline"
            size="sm"
            className="h-7"
            render={<Link href={`/portal/requests/${info.row.original.tt_number}`} />}
            onClick={(e) => e.stopPropagation()}
          >
            Open
          </Button>
        ),
      }),
    ],
    [],
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
    "h-8 min-w-[9.5rem] rounded-lg border border-input bg-transparent px-2.5 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50";

  return (
    <Card className={cn("gap-0 py-0 shadow-sm", compact && "is-compact")}>
      {!compact && (
        <div className="flex flex-wrap items-center justify-between gap-3 border-b px-4 py-3">
          <form className="flex min-w-[min(100%,18rem)] flex-1 gap-2" onSubmit={applySearch}>
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
            <Button type="submit" variant="outline" size="sm" className="h-8">
              Search
            </Button>
          </form>

          <div className="flex flex-wrap items-center gap-2">
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

      <CardContent className="px-0">
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
            {isLoading ? (
              <TableRow className="hover:bg-transparent">
                <TableCell colSpan={columns.length} className="p-0">
                  <Empty className="border-0 py-12">
                    <EmptyHeader>
                      <EmptyTitle>Loading requests…</EmptyTitle>
                    </EmptyHeader>
                  </Empty>
                </TableCell>
              </TableRow>
            ) : items.length === 0 ? (
              <TableRow className="hover:bg-transparent">
                <TableCell colSpan={columns.length} className="p-0 whitespace-normal">
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
                </TableCell>
              </TableRow>
            ) : (
              table.getRowModel().rows.map((row) => (
                <TableRow
                  key={row.id}
                  className="cursor-pointer"
                  tabIndex={0}
                  onClick={() =>
                    router.push(`/portal/requests/${row.original.tt_number}`)
                  }
                  onKeyDown={(e) => {
                    if (e.key === "Enter" || e.key === " ") {
                      e.preventDefault();
                      router.push(`/portal/requests/${row.original.tt_number}`);
                    }
                  }}
                >
                  {row.getVisibleCells().map((cell) => (
                    <TableCell key={cell.id} className="px-4 py-3 whitespace-normal">
                      {flexRender(cell.column.columnDef.cell, cell.getContext())}
                    </TableCell>
                  ))}
                </TableRow>
              ))
            )}
          </TableBody>
        </Table>
      </CardContent>

      {!compact && items.length > 0 && (
        <CardFooter className="justify-between gap-3 border-t bg-muted/30 py-3">
          <p className="text-sm text-muted-foreground">
            {`Page ${currentPage} of ${lastPage} · ${total} request${total === 1 ? "" : "s"}`}
            {isFetching && !isLoading ? " · Updating…" : ""}
          </p>
          <div className="flex gap-2">
            <Button
              type="button"
              variant="outline"
              size="sm"
              disabled={currentPage <= 1}
              onClick={() => setPage((p) => Math.max(1, p - 1))}
            >
              Previous
            </Button>
            <Button
              type="button"
              variant="outline"
              size="sm"
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
