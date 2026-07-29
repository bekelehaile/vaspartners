"use client";

import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { FormEvent, useEffect, useState } from "react";
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
  CardFooter,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import {
  useTicket,
  useDeleteRejectedTicket,
  useUpdateTicket,
} from "@/hooks/use-contact";
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
  const updateTicket = useUpdateTicket(requestNumber);
  const [tab, setTab] = useState<DetailTab>("overview");
  const [autoOpenedDocs, setAutoOpenedDocs] = useState(false);
  const [editing, setEditing] = useState(false);
  const [description, setDescription] = useState("");
  const [editError, setEditError] = useState<string | null>(null);
  const [editSaved, setEditSaved] = useState(false);

  const canEdit =
    !!ticket &&
    (ticket.contact_can_edit === true ||
      ticket.status === "open" ||
      ticket.status === "rejected");

  const canDelete =
    !!ticket &&
    (ticket.can_delete === true ||
      ticket.status === "open" ||
      ticket.status === "rejected");

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

  useEffect(() => {
    if (!ticket) return;
    setDescription(ticket.description ?? "");
  }, [ticket?.tt_number, ticket?.description]);

  useEffect(() => {
    if (!ticket || !canEdit || typeof window === "undefined") return;
    if (new URLSearchParams(window.location.search).get("edit") === "1") {
      setEditing(true);
      setTab("overview");
      setEditSaved(false);
      setEditError(null);
    }
  }, [ticket?.tt_number, canEdit]);

  function startEdit() {
    if (!ticket || !canEdit) return;
    setDescription(ticket.description ?? "");
    setEditing(true);
    setEditError(null);
    setEditSaved(false);
    setTab("overview");
    router.replace(`/portal/requests/${ticket.tt_number}?edit=1`, { scroll: false });
  }

  function cancelEdit() {
    if (!ticket) return;
    setEditing(false);
    setDescription(ticket.description ?? "");
    setEditError(null);
    setEditSaved(false);
    router.replace(`/portal/requests/${ticket.tt_number}`, { scroll: false });
  }

    async function onSaveEdit(e: FormEvent) {
    e.preventDefault();
    if (!ticket || !canEdit) return;
    const next = description.trim();
    if (!next) {
      setEditError("Please enter a description.");
      return;
    }
    if (
      ticket.status === "rejected" &&
      ticket.attachment_status?.state === "incomplete"
    ) {
      setEditError(
        ticket.attachment_status.missing_names?.length
          ? `Upload required documents first: ${ticket.attachment_status.missing_names.join(", ")}.`
          : "Upload all required documents before submitting.",
      );
      setTab("documents");
      return;
    }
    setEditError(null);
    try {
      const wasRejected = ticket.status === "rejected";
      await updateTicket.mutateAsync({ description: next });
      setEditing(false);
      setEditSaved(true);
      router.replace(`/portal/requests/${ticket.tt_number}`, { scroll: false });
      if (wasRejected) {
        setTab("overview");
      }
    } catch (err) {
      setEditError(err instanceof Error ? err.message : "Could not save changes.");
    }
  }

  function onDeleteRequest() {
    if (!ticket || !canDelete) return;
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
  }

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
        actions={<JourneyLaunchActions />}
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

        {editSaved && (
          <div className="alert" role="status" style={{ marginBottom: "1rem" }}>
            {ticket?.status === "open"
              ? "Request submitted. Status is now Pending for re-check."
              : "Request details saved."}
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
                    <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
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
                          Handled by
                        </dt>
                        <dd className="mt-1 font-semibold text-foreground">
                          {ticket.assignee?.name || "Awaiting assignment"}
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
                    <div className="flex flex-wrap items-start justify-between gap-3">
                      <div className="min-w-0 space-y-1.5">
                        <CardTitle>Progress</CardTitle>
                        <CardDescription>
                          {statusCopy[ticket.status]?.hint ||
                            "Track where this request is in the review flow."}
                        </CardDescription>
                      </div>
                      <StatusPill status={ticket.status} />
                    </div>
                  </CardHeader>
                  <CardContent className="flex flex-col gap-5 pt-(--card-spacing)">
                    <StatusJourney status={ticket.status} />

                    {editing && canEdit ? (
                      <form className="rounded-lg border bg-muted/30 px-4 py-3" onSubmit={onSaveEdit}>
                        <h3 className="mb-1 text-[0.72rem] font-bold tracking-wide text-muted-foreground uppercase">
                          Description
                        </h3>
                        <p className="muted" style={{ marginTop: 0, marginBottom: "0.75rem" }}>
                          {ticket.status === "rejected"
                            ? "Update your notes and documents, then submit. Status will return to Pending for re-check."
                            : "You can update this request while it is still pending."}
                        </p>
                        {editError && (
                          <div className="alert" role="alert" style={{ marginBottom: "0.75rem" }}>
                            {editError}
                          </div>
                        )}
                        <textarea
                          rows={5}
                          value={description}
                          onChange={(e) => setDescription(e.target.value)}
                          disabled={updateTicket.isPending}
                          placeholder="Describe why you need this service"
                          required
                          style={{ width: "100%" }}
                        />
                        <div className="form-actions" style={{ marginTop: "0.75rem" }}>
                          <Button
                            type="submit"
                            className="min-h-11"
                            disabled={updateTicket.isPending}
                          >
                            {updateTicket.isPending
                              ? ticket.status === "rejected"
                                ? "Submitting…"
                                : "Saving…"
                              : ticket.status === "rejected"
                                ? "Submit"
                                : "Save changes"}
                          </Button>
                          <Button
                            type="button"
                            variant="outline"
                            className="min-h-11"
                            disabled={updateTicket.isPending}
                            onClick={cancelEdit}
                          >
                            Cancel
                          </Button>
                          {ticket.status === "rejected" && (
                            <Button
                              type="button"
                              variant="outline"
                              className="min-h-11"
                              onClick={() => setTab("documents")}
                            >
                              Go to documents
                            </Button>
                          )}
                        </div>
                      </form>
                    ) : ticket.description ? (
                      <div className="rounded-lg border bg-muted/30 px-4 py-3">
                        <h3 className="mb-1 text-[0.72rem] font-bold tracking-wide text-muted-foreground uppercase">
                          Description
                        </h3>
                        <p className="whitespace-pre-wrap text-sm leading-relaxed text-foreground">
                          {ticket.description}
                        </p>
                      </div>
                    ) : null}
                  </CardContent>
                  {(canEdit || canDelete) && (
                    <CardFooter className="flex flex-wrap items-center gap-2 border-t bg-muted/20 py-3">
                      {canEdit && !editing && (
                        <Button
                          type="button"
                          variant="outline"
                          className="min-h-11"
                          onClick={startEdit}
                        >
                          Edit request
                        </Button>
                      )}
                      {ticket.status === "rejected" && !editing && (
                        <Button
                          type="button"
                          variant="outline"
                          className="min-h-11"
                          onClick={() => setTab("documents")}
                        >
                          Update documents
                        </Button>
                      )}
                      {canDelete && (
                        <Button
                          type="button"
                          variant="outline"
                          className="border-destructive/40 text-destructive hover:bg-destructive/10 min-h-11"
                          disabled={deleteRejected.isPending}
                          onClick={onDeleteRequest}
                        >
                          {deleteRejected.isPending ? "Deleting…" : "Delete request"}
                        </Button>
                      )}
                    </CardFooter>
                  )}
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
                    {ticket.status === "rejected"
                      ? "Update documents below, then wait for our team to re-check."
                      : "Required attachments for this request type."}
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
                {(canEdit || canDelete) && (
                  <CardFooter className="flex flex-wrap items-center gap-2 border-t bg-muted/20 py-3">
                    {ticket.status === "rejected" && (
                      <Button
                        type="button"
                        className="min-h-11"
                        disabled={
                          updateTicket.isPending ||
                          ticket.attachment_status?.state === "incomplete"
                        }
                        onClick={() => {
                          setDescription(ticket.description ?? "");
                          setEditing(true);
                          setTab("overview");
                          void (async () => {
                            try {
                              if (ticket.attachment_status?.state === "incomplete") {
                                setEditError(
                                  "Upload all required documents before submitting.",
                                );
                                return;
                              }
                              await updateTicket.mutateAsync({
                                description: (ticket.description ?? "").trim() || "Updated request",
                              });
                              setEditing(false);
                              setEditSaved(true);
                            } catch (err) {
                              setEditError(
                                err instanceof Error
                                  ? err.message
                                  : "Could not submit request.",
                              );
                              setEditing(true);
                              setTab("overview");
                            }
                          })();
                        }}
                      >
                        {updateTicket.isPending ? "Submitting…" : "Submit for re-check"}
                      </Button>
                    )}
                    {canEdit && !editing && ticket.status !== "rejected" && (
                      <Button
                        type="button"
                        variant="outline"
                        className="min-h-11"
                        onClick={startEdit}
                      >
                        Edit request
                      </Button>
                    )}
                    {canDelete && (
                      <Button
                        type="button"
                        variant="outline"
                        className="border-destructive/40 text-destructive hover:bg-destructive/10 min-h-11"
                        disabled={deleteRejected.isPending}
                        onClick={onDeleteRequest}
                      >
                        {deleteRejected.isPending ? "Deleting…" : "Delete request"}
                      </Button>
                    )}
                  </CardFooter>
                )}
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
