"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { JourneyLaunchActions, PortalPageHeader } from "@/components/PortalPageHeader";
import { StatusJourney } from "@/components/StatusJourney";
import { TicketChatPanel } from "@/components/TicketChatPanel";
import { TicketDocumentsPanel } from "@/components/TicketDocumentsPanel";
import { useTicket } from "@/hooks/use-customer";
import { statusCopy } from "@/lib/api";

export default function RequestDetailPage() {
  const params = useParams<{ public_id: string }>();
  const { data: ticket, isLoading, isError, error } = useTicket(params.public_id);

  return (
    <>
      <PortalPageHeader
        kicker={
          <Link href="/portal" className="linkish">
            ← My requests
          </Link>
        }
        title={ticket?.tt_number || "Request"}
        description={`${ticket?.service?.name || "Service"}${
          ticket?.requisition?.name ? ` · ${ticket.requisition.name}` : ""
        }`}
        actions={<JourneyLaunchActions />}
      />

      <div className="section section-flush">
        {isError && (
          <div className="alert">
            {error instanceof Error ? error.message : "Unable to load request"}
          </div>
        )}

        {isLoading || !ticket ? (
          <div className="panel">
            <div className="empty">Loading request…</div>
          </div>
        ) : (
          <div className="portal-grid">
            <section className="panel">
              <h2>Progress</h2>
              <StatusJourney status={ticket.status} />
              <p className="muted" style={{ marginTop: "1rem" }}>
                {statusCopy[ticket.status]?.hint}
              </p>
              {ticket.description && (
                <>
                  <h2 style={{ marginTop: "1.5rem" }}>Description</h2>
                  <p className="muted">{ticket.description}</p>
                </>
              )}

              <h2 style={{ marginTop: "1.5rem" }}>Documents</h2>
              <TicketDocumentsPanel
                ticket={ticket}
                mode="manage"
                serviceId={ticket.service?.id ? String(ticket.service.id) : undefined}
                requisitionId={
                  ticket.requisition?.id ? String(ticket.requisition.id) : undefined
                }
              />
            </section>

            <TicketChatPanel
              publicId={ticket.public_id}
              chatLocked={!!ticket.chat_locked}
              maxKb={ticket.chat_attachment_max_kb ?? 2048}
              initialMessages={ticket.messages ?? []}
              initialHasMoreOlder={!!ticket.messages_meta?.has_more_older}
              initialTotal={ticket.messages_meta?.total}
            />
          </div>
        )}
      </div>
    </>
  );
}
