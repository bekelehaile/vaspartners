"use client";

import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import {
  FileTextIcon,
  MessageSquareIcon,
  RouteIcon,
} from "lucide-react";
import { JourneyLaunchActions, PortalPageHeader } from "@/components/PortalPageHeader";
import { StatusJourney, StatusPill } from "@/components/StatusJourney";
import { TicketChatPanel } from "@/components/TicketChatPanel";
import { TicketDocumentsPanel } from "@/components/TicketDocumentsPanel";
import {
  AdminWorkspace,
  VerticalTabPanel,
  VerticalTabs,
} from "@/components/VerticalTabs";
import { Button } from "@/components/ui/button";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { useTicket, useDeleteRejectedTicket } from "@/hooks/use-contact";
import { statusCopy } from "@/lib/api";

type DetailTab = "overview" | "documents" | "messages";

export default function RequestDetailPage() {
  const params = useParams<{ tt_number: string | string[] }>();
  const raw = params.tt_number;
  const requestNumber = decodeURIComponent(
    Array.isArray(raw) ? raw[0] ?? "" : raw ?? "",
  );
  const router = useRouter();
  const { data: ticket, isLoading, isError, error } = useTicket(requestNumber);
  const deleteRejected = useDeleteRejectedTicket();
  const [tab, setTab] = useState<DetailTab>("overview");
  const [autoOpenedDocs, setAutoOpenedDocs] = useState(false);

  const docsIncomplete =
    !!ticket &&
    !ticket.documents_locked &&
    ticket.attachment_status?.state === "incomplete";

  useEffect(() => {
    if (!ticket || autoOpenedDocs) return;
    if (docsIncomplete) {
      setTab("documents");
      setAutoOpenedDocs(true);
    }
  }, [ticket, docsIncomplete, autoOpenedDocs]);

  return (
    <>
      <PortalPageHeader
        kicker={
          <Button variant="link" size="sm" className="h-auto px-0" render={<Link href="/portal" />}>
            ← Service requests
          </Button>
        }
        title={ticket ? ticket.tt_number : "Request"}
        description={
          ticket
            ? [ticket.service?.name, ticket.requisition?.name]
                .filter(Boolean)
                .join(" · ") || "Service request details"
            : "Loading request details…"
        }
        actions={
          ticket ? (
            <div className="portal-request-toolbar">
              <div className="flex flex-wrap items-center gap-2">
                <StatusPill status={ticket.status} />
              </div>
              {(ticket.can_delete ||
                ticket.status === "open" ||
                ticket.status === "rejected") && (
                <Button
                  type="button"
                  variant="outline"
                  className="border-destructive/40 text-destructive hover:bg-destructive/10 min-h-11"
                  disabled={deleteRejected.isPending}
                  onClick={() => {
                    const open = ticket.status === "open";
                    const ok = window.confirm(
                      open
                        ? `Permanently delete request ${ticket.tt_number}? Ethio telecom has not started handling it yet. This removes the request and uploaded documents. This cannot be undone.`
                        : `Permanently delete rejected request ${ticket.tt_number}? This removes the request, messages, and all uploaded documents from the system. This cannot be undone.`,
                    );
                    if (!ok) return;
                    void deleteRejected
                      .mutateAsync(ticket.tt_number)
                      .then(() => router.replace("/portal"))
                      .catch(() => undefined);
                  }}
                >
                  {deleteRejected.isPending ? "Deleting…" : "Delete request"}
                </Button>
              )}
              <JourneyLaunchActions />
            </div>
          ) : (
            <JourneyLaunchActions />
          )
        }
      />

      <div className="section section-flush">
        {isError && (
          <div className="alert">
            {error instanceof Error ? error.message : "Unable to load request"}
          </div>
        )}

        {deleteRejected.isError && (
          <div className="alert" role="alert" style={{ marginBottom: "1rem" }}>
            {deleteRejected.error instanceof Error
              ? deleteRejected.error.message
              : "Could not delete this request"}
          </div>
        )}

        {docsIncomplete && (
          <div className="alert" role="status" style={{ marginBottom: "1rem" }}>
            <strong>Upload required documents.</strong>{" "}
            {ticket?.attachment_status?.missing_count
              ? `${ticket.attachment_status.missing_count} still missing`
              : "Some required files are missing"}
            {ticket?.attachment_status?.missing_names?.length
              ? `: ${ticket.attachment_status.missing_names.join(", ")}`
              : ""}
            . Open the Documents tab and attach every file marked *.
            <button
              type="button"
              className="linkish"
              style={{ marginLeft: "0.5rem" }}
              onClick={() => setTab("documents")}
            >
              Go to documents
            </button>
          </div>
        )}

        {isLoading || !ticket ? (
          <Card>
            <CardContent className="py-12 text-center text-muted-foreground">
              Loading request…
            </CardContent>
          </Card>
        ) : (
          <AdminWorkspace
            sidebar={
              <VerticalTabs
                label="Request sections"
                value={tab}
                onChange={setTab}
                items={[
                  {
                    id: "overview",
                    label: "Overview",
                    description: "Progress and details",
                    icon: <RouteIcon className="size-4" />,
                  },
                  {
                    id: "documents",
                    label: docsIncomplete
                      ? `Documents (${ticket.attachment_status?.missing_count ?? "!"})`
                      : "Documents",
                    description: docsIncomplete
                      ? "Required attachments missing"
                      : "Required attachments",
                    icon: <FileTextIcon className="size-4" />,
                  },
                  {
                    id: "messages",
                    label: "Messages",
                    description: ticket.chat_locked ? "Chat locked" : "Discussion",
                    icon: <MessageSquareIcon className="size-4" />,
                  },
                ]}
              />
            }
          >
            <VerticalTabPanel
              id="overview"
              active={tab === "overview"}
              labelledBy="vtab-overview"
            >
              <div className="flex flex-col gap-5">
                <Card size="sm">
                  <CardContent className="pt-(--card-spacing)">
                    <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                      <div>
                        <dt className="text-[0.72rem] font-bold tracking-wide text-muted-foreground uppercase">
                          Service
                        </dt>
                        <dd className="mt-1 font-semibold text-foreground">
                          {ticket.service?.name || "—"}
                        </dd>
                      </div>
                      <div>
                        <dt className="text-[0.72rem] font-bold tracking-wide text-muted-foreground uppercase">
                          Request type
                        </dt>
                        <dd className="mt-1 font-semibold text-foreground">
                          {ticket.requisition?.name || "—"}
                        </dd>
                      </div>
                      <div>
                        <dt className="text-[0.72rem] font-bold tracking-wide text-muted-foreground uppercase">
                          Submitted by
                        </dt>
                        <dd className="mt-1 font-semibold text-foreground">
                          {ticket.contact?.name || "—"}
                        </dd>
                      </div>
                      <div>
                        <dt className="text-[0.72rem] font-bold tracking-wide text-muted-foreground uppercase">
                          Submitted
                        </dt>
                        <dd className="mt-1 font-semibold text-foreground">
                          {ticket.created_at
                            ? new Date(ticket.created_at).toLocaleString(undefined, {
                                year: "numeric",
                                month: "short",
                                day: "numeric",
                                hour: "2-digit",
                                minute: "2-digit",
                              })
                            : "—"}
                        </dd>
                      </div>
                    </dl>
                  </CardContent>
                </Card>

                <Card>
                  <CardHeader className="border-b">
                    <CardTitle>Progress</CardTitle>
                    <CardDescription>
                      {statusCopy[ticket.status]?.hint ||
                        "Track where this request is in the review flow."}
                    </CardDescription>
                  </CardHeader>
                  <CardContent className="flex flex-col gap-5 pt-(--card-spacing)">
                    <StatusJourney status={ticket.status} />

                    {ticket.description && (
                      <div className="rounded-lg border bg-muted/30 px-4 py-3">
                        <h3 className="mb-1 text-[0.72rem] font-bold tracking-wide text-muted-foreground uppercase">
                          Description
                        </h3>
                        <p className="whitespace-pre-wrap text-sm leading-relaxed text-foreground">
                          {ticket.description}
                        </p>
                      </div>
                    )}
                  </CardContent>
                </Card>
              </div>
            </VerticalTabPanel>

            <VerticalTabPanel
              id="documents"
              active={tab === "documents"}
              labelledBy="vtab-documents"
            >
              <Card>
                <CardHeader className="border-b">
                  <CardTitle>Documents</CardTitle>
                  <CardDescription>
                    Required attachments for this request type.
                  </CardDescription>
                </CardHeader>
                <CardContent className="pt-(--card-spacing)">
                  <TicketDocumentsPanel
                    ticket={ticket}
                    mode="manage"
                    serviceId={
                      ticket.service?.id ? String(ticket.service.id) : undefined
                    }
                    requisitionId={
                      ticket.requisition?.id
                        ? String(ticket.requisition.id)
                        : undefined
                    }
                  />
                </CardContent>
              </Card>
            </VerticalTabPanel>

            <VerticalTabPanel
              id="messages"
              active={tab === "messages"}
              labelledBy="vtab-messages"
            >
              <TicketChatPanel
                publicId={ticket.tt_number}
                chatLocked={!!ticket.chat_locked}
                maxKb={ticket.chat_attachment_max_kb ?? 2048}
                initialMessages={ticket.messages ?? []}
                initialHasMoreOlder={!!ticket.messages_meta?.has_more_older}
                initialTotal={ticket.messages_meta?.total}
              />
            </VerticalTabPanel>
          </AdminWorkspace>
        )}
      </div>
    </>
  );
}
