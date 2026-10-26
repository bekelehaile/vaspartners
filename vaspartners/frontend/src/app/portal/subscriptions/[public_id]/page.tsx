"use client";

import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { useState } from "react";
import { JourneyLaunchActions, PortalPageHeader } from "@/components/PortalPageHeader";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { useContact, useSubscription } from "@/hooks/use-contact";
import {
  contactCanManageProvisioning,
  contactCanManageServices,
  isAliveSubscriptionStatus,
  subscriptionStatusLabel,
} from "@/lib/company-permissions";
import { startProvisioning, statusCopy } from "@/lib/api";

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

export default function SubscriptionDetailPage() {
  const params = useParams<{ public_id: string | string[] }>();
  const raw = params.public_id;
  const publicId = decodeURIComponent(Array.isArray(raw) ? raw[0] ?? "" : raw ?? "");

  const router = useRouter();
  const { data: me } = useContact();
  const { data: sub, isLoading, isError, error } = useSubscription(publicId);
  const [shortCode, setShortCode] = useState("");
  const [dataSyncUrl, setDataSyncUrl] = useState("");
  const [startError, setStartError] = useState<string | null>(null);
  const [starting, setStarting] = useState(false);

  const canManage = contactCanManageServices(me);
  const canProvision = contactCanManageProvisioning(me);
  const alive = isAliveSubscriptionStatus(sub?.status);
  const contractSigned = Boolean(sub?.contract_signed_at);
  const canStartProvisioning = Boolean(sub && canProvision && alive && contractSigned);

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
              <Link
                href={`/portal/requests/new?intent=manage&subscription_id=${sub.id}`}
                className="btn-ghost"
              >
                Manage
              </Link>
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
            {canStartProvisioning ? (
              <Card size="sm">
                <CardHeader>
                  <CardTitle>Service provisioning</CardTitle>
                  <CardDescription>
                    Contract is signed. Request resource allocation to start provisioning (PRV case).
                  </CardDescription>
                </CardHeader>
                <CardContent className="space-y-3">
                  <div className="grid gap-3 sm:grid-cols-2">
                    <label className="block text-sm">
                      <span className="mb-1 block font-medium">Short code</span>
                      <input
                        className="w-full rounded-md border border-border bg-background px-3 py-2"
                        value={shortCode}
                        onChange={(e) => setShortCode(e.target.value)}
                        placeholder="e.g. 8080"
                        required
                      />
                    </label>
                    <label className="block text-sm">
                      <span className="mb-1 block font-medium">Data Sync URL (optional)</span>
                      <input
                        className="w-full rounded-md border border-border bg-background px-3 py-2"
                        value={dataSyncUrl}
                        onChange={(e) => setDataSyncUrl(e.target.value)}
                        placeholder="https://"
                      />
                    </label>
                  </div>
                  {startError ? <p className="text-sm text-destructive">{startError}</p> : null}
                  <button
                    type="button"
                    className="btn-primary"
                    disabled={starting || !shortCode.trim()}
                    onClick={async () => {
                      setStartError(null);
                      setStarting(true);
                      try {
                        const created = await startProvisioning({
                          subscription_public_id: sub.public_id,
                          short_code: shortCode.trim(),
                          data_sync_url: dataSyncUrl.trim() || undefined,
                        });
                        router.push(`/portal/provisioning/${encodeURIComponent(created.public_id)}`);
                      } catch (e) {
                        setStartError(e instanceof Error ? e.message : "Could not start provisioning");
                      } finally {
                        setStarting(false);
                      }
                    }}
                  >
                    {starting ? "Submitting…" : "Request resource allocation"}
                  </button>
                  <p className="text-xs text-muted-foreground">
                    Or open existing cases from{" "}
                    <Link href="/portal/provisioning" className="text-primary hover:underline">
                      Provisioning
                    </Link>
                    .
                  </p>
                </CardContent>
              </Card>
            ) : null}

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
