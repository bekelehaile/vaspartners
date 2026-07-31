"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { JourneyLaunchActions, PortalPageHeader } from "@/components/PortalPageHeader";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { useContact, useServices, useSubscription } from "@/hooks/use-contact";
import type { Service } from "@/lib/api";
import {
  contactCanManageServices,
  isAliveSubscriptionStatus,
  subscriptionStatusLabel,
} from "@/lib/company-permissions";
import { statusCopy } from "@/lib/api";

function formatDateTime(value?: string | null): string {
  if (!value) return "—";
  return new Date(value).toLocaleString(undefined, {
    year: "numeric",
    month: "short",
    day: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  });
}

function formatDate(value?: string | null): string {
  if (!value) return "—";
  return new Date(value).toLocaleDateString(undefined, {
    year: "numeric",
    month: "short",
    day: "numeric",
  });
}

function serviceAllowsTermination(services: Service[], serviceId?: number): boolean {
  if (!serviceId) return false;
  const svc = services.find((s) => s.id === serviceId);
  if (!svc || svc.is_subscription_based === false) return false;
  return (svc.requisitions ?? []).some((r) => !!r.terminates_subscription);
}

export default function SubscriptionDetailPage() {
  const params = useParams<{ public_id: string | string[] }>();
  const raw = params.public_id;
  const publicId = decodeURIComponent(Array.isArray(raw) ? raw[0] ?? "" : raw ?? "");

  const { data: me } = useContact();
  const { data: services = [] } = useServices();
  const { data: sub, isLoading, isError, error } = useSubscription(publicId);

  const canManage = contactCanManageServices(me);
  const alive = isAliveSubscriptionStatus(sub?.status);
  const canTerminate =
    !!sub && alive && canManage && serviceAllowsTermination(services, sub.service?.id);

  return (
    <>
      <PortalPageHeader
        title={sub?.service?.name || "Subscription"}
        description={
          sub
            ? `${sub.public_id} · ${subscriptionStatusLabel(sub.status_label || sub.status)}`
            : "Subscription details for your current company."
        }
        actions={
          <div className="portal-header-actions">
            <Link href="/portal/subscriptions" className="btn-ghost">
              Back to subscriptions
            </Link>
            {sub && canManage && alive ? (
              <>
                <Link
                  href={`/portal/requests/new?intent=manage&subscription_id=${sub.id}`}
                  className="btn-ghost"
                >
                  Manage
                </Link>
                {canTerminate ? (
                  <Link
                    href={`/portal/requests/new?intent=manage&subscription_id=${sub.id}&action=terminate`}
                    className="btn-ghost"
                  >
                    Terminate
                  </Link>
                ) : null}
              </>
            ) : (
              <JourneyLaunchActions />
            )}
          </div>
        }
      />

      <div className="section section-flush">
        {isError && (
          <div className="alert">
            {error instanceof Error ? error.message : "Unable to load subscription"}
          </div>
        )}

        {isLoading || !sub ? (
          <Card>
            <CardContent className="py-12 text-center text-muted-foreground">
              Loading subscription…
            </CardContent>
          </Card>
        ) : (
          <div className="flex flex-col gap-5">
            <Card size="sm">
              <CardContent className="pt-(--card-spacing)">
                <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                  <div>
                    <dt className="text-[0.72rem] font-bold tracking-wide text-muted-foreground uppercase">
                      Status
                    </dt>
                    <dd className="mt-1">
                      <span className={`status-chip${alive ? " is-alive" : " is-ended"}`}>
                        {subscriptionStatusLabel(sub.status_label || sub.status)}
                      </span>
                    </dd>
                  </div>
                  <div>
                    <dt className="text-[0.72rem] font-bold tracking-wide text-muted-foreground uppercase">
                      Service
                    </dt>
                    <dd className="mt-1 font-semibold text-foreground">
                      {sub.service?.name || "—"}
                    </dd>
                  </div>
                  <div>
                    <dt className="text-[0.72rem] font-bold tracking-wide text-muted-foreground uppercase">
                      Company
                    </dt>
                    <dd className="mt-1 font-semibold text-foreground">
                      {sub.company?.name || "—"}
                      {sub.company?.tin ? (
                        <span className="muted"> · TIN {sub.company.tin}</span>
                      ) : null}
                    </dd>
                  </div>
                  <div>
                    <dt className="text-[0.72rem] font-bold tracking-wide text-muted-foreground uppercase">
                      Renewal
                    </dt>
                    <dd className="mt-1 font-semibold text-foreground">
                      {sub.renewal_interval_label || sub.renewal_interval || "—"}
                    </dd>
                  </div>
                  <div>
                    <dt className="text-[0.72rem] font-bold tracking-wide text-muted-foreground uppercase">
                      Started
                    </dt>
                    <dd className="mt-1 font-semibold text-foreground">
                      {formatDate(sub.started_at)}
                    </dd>
                  </div>
                  <div>
                    <dt className="text-[0.72rem] font-bold tracking-wide text-muted-foreground uppercase">
                      Current period
                    </dt>
                    <dd className="mt-1 font-semibold text-foreground">
                      {formatDate(sub.current_period_start)} → {formatDate(sub.current_period_end)}
                    </dd>
                  </div>
                  <div>
                    <dt className="text-[0.72rem] font-bold tracking-wide text-muted-foreground uppercase">
                      Next renewal
                    </dt>
                    <dd className="mt-1 font-semibold text-foreground">
                      {formatDate(sub.next_renewal_due_at)}
                    </dd>
                  </div>
                  <div>
                    <dt className="text-[0.72rem] font-bold tracking-wide text-muted-foreground uppercase">
                      Deactivated
                    </dt>
                    <dd className="mt-1 font-semibold text-foreground">
                      {formatDate(sub.terminated_at)}
                    </dd>
                  </div>
                </dl>
              </CardContent>
            </Card>

            <Card>
              <CardHeader className="border-b">
                <CardTitle>Activation</CardTitle>
                <CardDescription>
                  Who activated this subscription and the related service request.
                </CardDescription>
              </CardHeader>
              <CardContent className="pt-(--card-spacing)">
                <dl className="grid gap-4 sm:grid-cols-2">
                  <div>
                    <dt className="text-[0.72rem] font-bold tracking-wide text-muted-foreground uppercase">
                      Activated by
                    </dt>
                    <dd className="mt-1 font-semibold text-foreground">
                      {sub.activated_by_contact?.name || "—"}
                      {sub.activated_by_contact?.phone_number ? (
                        <span className="muted">
                          {" "}
                          · {sub.activated_by_contact.phone_number}
                        </span>
                      ) : null}
                    </dd>
                  </div>
                  <div>
                    <dt className="text-[0.72rem] font-bold tracking-wide text-muted-foreground uppercase">
                      Activation request
                    </dt>
                    <dd className="mt-1 font-semibold text-foreground">
                      {sub.activated_by_ticket?.tt_number ? (
                        <Link
                          href={`/portal/requests/${sub.activated_by_ticket.tt_number}`}
                          className="table-link"
                        >
                          {sub.activated_by_ticket.tt_number}
                          {sub.activated_by_ticket.requisition?.name
                            ? ` · ${sub.activated_by_ticket.requisition.name}`
                            : ""}
                        </Link>
                      ) : (
                        "—"
                      )}
                    </dd>
                  </div>
                  {sub.terminated_by_ticket?.tt_number ? (
                    <div>
                      <dt className="text-[0.72rem] font-bold tracking-wide text-muted-foreground uppercase">
                        Deactivation request
                      </dt>
                      <dd className="mt-1 font-semibold text-foreground">
                        <Link
                          href={`/portal/requests/${sub.terminated_by_ticket.tt_number}`}
                          className="table-link"
                        >
                          {sub.terminated_by_ticket.tt_number}
                          {sub.terminated_by_ticket.requisition?.name
                            ? ` · ${sub.terminated_by_ticket.requisition.name}`
                            : ""}
                        </Link>
                      </dd>
                    </div>
                  ) : null}
                </dl>
              </CardContent>
            </Card>

            <Card>
              <CardHeader className="border-b">
                <CardTitle>Related requests</CardTitle>
                <CardDescription>
                  Service requests linked to this subscription (newest first).
                </CardDescription>
              </CardHeader>
              <CardContent className="pt-(--card-spacing)">
                {!sub.tickets?.length ? (
                  <p className="muted">No linked requests yet.</p>
                ) : (
                  <ul className="portal-mobile-list" style={{ display: "grid", gap: "0.75rem" }}>
                    {sub.tickets.map((ticket) => (
                      <li key={ticket.tt_number}>
                        <Link
                          href={`/portal/requests/${ticket.tt_number}`}
                          className="portal-mobile-card"
                          style={{ display: "block", textDecoration: "none" }}
                        >
                          <div className="portal-mobile-card-top">
                            <div>
                              <p className="portal-mobile-card-title">{ticket.tt_number}</p>
                              <p className="portal-mobile-card-meta">
                                {ticket.requisition?.name || "Request"}
                                {ticket.service?.name ? ` · ${ticket.service.name}` : ""}
                              </p>
                            </div>
                            <span className="status-chip">
                              {statusCopy[ticket.status as keyof typeof statusCopy]?.label ||
                                ticket.status}
                            </span>
                          </div>
                          <div className="portal-mobile-card-row">
                            <span>{formatDateTime(ticket.created_at)}</span>
                            <span>View request</span>
                          </div>
                        </Link>
                      </li>
                    ))}
                  </ul>
                )}
              </CardContent>
            </Card>
          </div>
        )}
      </div>
    </>
  );
}
