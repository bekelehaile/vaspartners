"use client";

import Link from "next/link";
import { useForm, useStore } from "@tanstack/react-form";
import { useEffect, useMemo, useState } from "react";
import { useSearchParams } from "next/navigation";
import { PortalPageHeader } from "@/components/PortalPageHeader";
import {
  useCreateTicket,
  useDocumentRequirements,
  useServices,
  useSubscriptions,
  useTicket,
  uploadTicketDocumentFile,
} from "@/hooks/use-customer";
import type { Service, Subscription, Ticket } from "@/lib/api";
import { documentsLockedStatus } from "@/lib/document-upload";
import { ticketCreateSchema } from "@/lib/schemas/ticket";
import { TicketDocumentsPanel } from "@/components/TicketDocumentsPanel";
import {
  RequirementsPreview,
  requiredAttachmentsReady,
  type StagedAttachments,
} from "@/components/RequirementsPreview";

type Requisition = NonNullable<Service["requisitions"]>[number];
type Intent = "subscribe" | "manage";

const ALIVE_STATUSES = new Set(["active", "pending_renewal", "grace"]);

function isAliveSubscription(sub: Subscription): boolean {
  return ALIVE_STATUSES.has(String(sub.status || "").toLowerCase());
}

function starterRequisitions(service: Service): Requisition[] {
  // Subscribe journey is only for subscription-based products
  if (service.is_subscription_based === false) return [];
  return (service.requisitions ?? []).filter((r) => !!r.creates_subscription);
}

function manageRequisitions(service: Service): Requisition[] {
  const reqs = service.requisitions ?? [];
  // Non-subscription services are requested via Manage (no active sub required)
  if (service.is_subscription_based === false) {
    return reqs.filter((r) => !r.creates_subscription);
  }
  return reqs.filter(
    (r) =>
      !!r.requires_active_subscription ||
      !!r.renews_subscription ||
      !!r.terminates_subscription
  );
}

function stepLabels(intent: Intent): string[] {
  return intent === "subscribe"
    ? ["Service", "Confirm", "Documents"]
    : ["Service", "Change type", "Confirm", "Documents"];
}

export default function NewRequestWizard() {
  const params = useSearchParams();
  const presetService = params.get("service") || "";
  const presetIntentParam = params.get("intent");
  const presetIntent: Intent | "" =
    presetIntentParam === "subscribe" || presetIntentParam === "manage"
      ? presetIntentParam
      : "";

  const { data: services = [], isLoading: servicesLoading } = useServices();
  const { data: subscriptionData, isLoading: subsLoading } = useSubscriptions();
  const createTicket = useCreateTicket();
  const subscriptions = subscriptionData?.items ?? [];
  const pendingNewServiceIds = useMemo(
    () => new Set(subscriptionData?.pendingNewServiceIds ?? []),
    [subscriptionData?.pendingNewServiceIds]
  );
  const pendingByServiceRequisition = useMemo(() => {
    const map = new Map<
      string,
      { tt_number: string; public_id: string; status: string }
    >();
    for (const row of subscriptionData?.pendingRequests ?? []) {
      map.set(`${row.service_id}:${row.requisition_id}`, {
        tt_number: row.tt_number,
        public_id: row.public_id,
        status: row.status,
      });
    }
    return map;
  }, [subscriptionData?.pendingRequests]);
  const pendingFor = (serviceIdNum: number, requisitionIdNum: number) =>
    pendingByServiceRequisition.get(`${serviceIdNum}:${requisitionIdNum}`);

  const aliveSubs = useMemo(
    () => subscriptions.filter(isAliveSubscription),
    [subscriptions]
  );
  const subscribedServiceIds = useMemo(
    () =>
      new Set(
        aliveSubs.map((s) => Number(s.service?.id ?? s.service_id)).filter(Boolean)
      ),
    [aliveSubs]
  );

  const subscribeServices = useMemo(
    () =>
      services.filter((s) => {
        if (s.is_subscription_based === false) return false;
        if (!starterRequisitions(s).length) return false;
        if (subscribedServiceIds.has(s.id)) return false;
        if (pendingNewServiceIds.has(s.id)) return false;
        return true;
      }),
    [services, subscribedServiceIds, pendingNewServiceIds]
  );

  /** One-off / non-subscription services — managed by flag, no active sub needed. */
  const manageOneOffServices = useMemo(
    () =>
      services.filter(
        (s) => s.is_subscription_based === false && manageRequisitions(s).length > 0
      ),
    [services]
  );

  const canManage =
    aliveSubs.length > 0 || manageOneOffServices.length > 0;

  const [stagedFiles, setStagedFiles] = useState<StagedAttachments>({});
  const [attachError, setAttachError] = useState<string | null>(null);
  const [uploadingDocs, setUploadingDocs] = useState(false);

  const form = useForm({
    defaultValues: {
      intent: presetIntent as Intent | "",
      service_id: presetService,
      requisition_id: "",
      subscription_id: "",
      description: "",
    },
    onSubmit: async ({ value }) => {
      setAttachError(null);
      const parsed = ticketCreateSchema.parse(value);
      const created = await createTicket.mutateAsync(parsed);

      const entries = Object.entries(stagedFiles);
      if (entries.length) {
        setUploadingDocs(true);
        try {
          for (const [documentTypeId, file] of entries) {
            await uploadTicketDocumentFile(created.public_id, Number(documentTypeId), file);
          }
          setStagedFiles({});
        } catch (err) {
          setAttachError(
            err instanceof Error
              ? err.message
              : "Request created, but some documents failed to upload. You can retry on the next step."
          );
        } finally {
          setUploadingDocs(false);
        }
      }
    },
  });

  // useForm does not re-render the parent on field changes — subscribe explicitly.
  const values = useStore(form.store, (s) => s.values);
  const ticket = createTicket.data;
  const intent = values.intent as Intent | "";
  const serviceId = values.service_id;
  const subscriptionId = values.subscription_id;
  const requisitionId = values.requisition_id;
  const description = values.description;

  const selectedSubscribe = subscribeServices.find((s) => String(s.id) === String(serviceId));
  const selectedSub = aliveSubs.find((s) => String(s.id) === String(subscriptionId));
  const manageServiceId = selectedSub
    ? Number(selectedSub.service?.id ?? selectedSub.service_id ?? 0)
    : Number(serviceId || 0);
  const selectedManage =
    services.find((s) => s.id === manageServiceId) ||
    manageOneOffServices.find((s) => String(s.id) === String(serviceId));
  const managingOneOff = !!selectedManage && selectedManage.is_subscription_based === false;
  const starterTypes = selectedSubscribe ? starterRequisitions(selectedSubscribe) : [];
  const manageTypes = selectedManage ? manageRequisitions(selectedManage) : [];

  const confirmServiceId =
    intent === "manage" ? (manageServiceId ? String(manageServiceId) : "") : String(serviceId || "");
  const {
    data: confirmRequirements = [],
    isLoading: confirmDocsLoading,
    isFetched: confirmDocsFetched,
  } = useDocumentRequirements(confirmServiceId, String(requisitionId || ""));
  const attachmentsReady =
    (!!confirmServiceId && !!requisitionId
      ? confirmDocsFetched && !confirmDocsLoading
      : true) && requiredAttachmentsReady(confirmRequirements, stagedFiles);

  // Clear staged files when the request type / service changes
  useEffect(() => {
    setStagedFiles({});
    setAttachError(null);
  }, [serviceId, requisitionId, subscriptionId, intent]);

  // Wizard step within a journey (0-based after intent is chosen)
  const [step, setStep] = useState(0);
  const [deepLinkReady, setDeepLinkReady] = useState(!presetIntent);

  // Keep form intent in sync with deep-link CTAs (?intent=subscribe|manage)
  useEffect(() => {
    if (ticket) return;
    if (!presetIntent) {
      setDeepLinkReady(true);
      return;
    }
    form.setFieldValue("intent", presetIntent);
    if (presetService) {
      form.setFieldValue("service_id", presetService);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps -- deep-link only
  }, [presetIntent, presetService, ticket]);

  // Auto-pick sole requisition for subscribe path
  useEffect(() => {
    if (intent !== "subscribe" || !serviceId) return;
    if (starterTypes.length === 1 && requisitionId !== String(starterTypes[0].id)) {
      form.setFieldValue("requisition_id", String(starterTypes[0].id));
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps -- selection only
  }, [intent, serviceId, starterTypes.length, requisitionId]);

  // Auto-pick sole requisition for one-off manage path
  useEffect(() => {
    if (intent !== "manage" || !managingOneOff || !serviceId) return;
    if (manageTypes.length !== 1) return;
    const only = manageTypes[0];
    if (pendingFor(Number(serviceId), Number(only.id))) return;
    if (requisitionId !== String(only.id)) {
      form.setFieldValue("requisition_id", String(only.id));
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps -- selection only
  }, [intent, managingOneOff, serviceId, manageTypes.length, requisitionId, pendingByServiceRequisition]);

  // Deep-link shortcuts: skip chooser + jump ahead when context is clear
  useEffect(() => {
    if (ticket || !presetIntent) {
      setDeepLinkReady(true);
      return;
    }
    if (servicesLoading || subsLoading) return;

    if (presetIntent === "subscribe") {
      if (presetService) {
        const svc = subscribeServices.find((s) => String(s.id) === presetService);
        if (svc) {
          const starters = starterRequisitions(svc);
          if (starters.length === 1) {
            form.setFieldValue("requisition_id", String(starters[0].id));
          }
          setStep(1);
        }
      } else if (subscribeServices.length === 1) {
        const only = subscribeServices[0];
        form.setFieldValue("service_id", String(only.id));
        const starters = starterRequisitions(only);
        if (starters.length === 1) {
          form.setFieldValue("requisition_id", String(starters[0].id));
        }
      }
    }

    if (presetIntent === "manage") {
      if (presetService) {
        const oneOff = manageOneOffServices.find((s) => String(s.id) === presetService);
        if (oneOff) {
          form.setFieldValue("service_id", String(oneOff.id));
          form.setFieldValue("subscription_id", "");
          const types = manageRequisitions(oneOff);
          if (types.length === 1) {
            form.setFieldValue("requisition_id", String(types[0].id));
          }
          setStep(types.length === 1 ? 2 : 1);
        } else {
          const sub = aliveSubs.find(
            (s) => String(s.service?.id ?? s.service_id) === presetService
          );
          if (sub) {
            form.setFieldValue("subscription_id", String(sub.id));
            form.setFieldValue("service_id", String(sub.service?.id ?? sub.service_id ?? ""));
            setStep(1);
          }
        }
      } else if (aliveSubs.length === 1 && manageOneOffServices.length === 0) {
        const only = aliveSubs[0];
        form.setFieldValue("subscription_id", String(only.id));
        form.setFieldValue(
          "service_id",
          String(only.service?.id ?? only.service_id ?? "")
        );
      } else if (aliveSubs.length === 0 && manageOneOffServices.length === 1) {
        const only = manageOneOffServices[0];
        form.setFieldValue("service_id", String(only.id));
        form.setFieldValue("subscription_id", "");
        const types = manageRequisitions(only);
        if (types.length === 1) {
          form.setFieldValue("requisition_id", String(types[0].id));
        }
      }
    }

    setDeepLinkReady(true);
    // eslint-disable-next-line react-hooks/exhaustive-deps -- deep-link bootstrap
  }, [
    presetIntent,
    presetService,
    ticket,
    servicesLoading,
    subsLoading,
    subscribeServices.length,
    aliveSubs.length,
    manageOneOffServices.length,
  ]);

  const labels = intent ? stepLabels(intent) : [];

  function chooseIntent(next: Intent) {
    form.setFieldValue("intent", next);
    form.setFieldValue(
      "service_id",
      next === "subscribe" || next === "manage" ? presetService : ""
    );
    form.setFieldValue("requisition_id", "");
    form.setFieldValue("subscription_id", "");
    form.setFieldValue("description", "");
    setStagedFiles({});
    setAttachError(null);
    setStep(0);
  }

  function resetIntent() {
    form.setFieldValue("intent", "");
    form.setFieldValue("service_id", "");
    form.setFieldValue("requisition_id", "");
    form.setFieldValue("subscription_id", "");
    form.setFieldValue("description", "");
    setStagedFiles({});
    setAttachError(null);
    setStep(0);
  }

  const headerTitle =
    !intent
      ? "Choose your journey"
      : intent === "subscribe"
        ? "Start a new subscription"
        : "Manage a service";

  const headerDescription =
    !intent
      ? "Subscription and management are separate paths so you only see the options that apply."
      : intent === "subscribe"
        ? "Activate a subscription-based VAS product you do not already have."
        : "Change an active subscription, or request a non-subscription service.";

  return (
    <>
      <PortalPageHeader
        kicker="Service request"
        title={headerTitle}
        description={headerDescription}
        actions={
          <Link href="/portal" className="btn-ghost">
            Back to requests
          </Link>
        }
      />

      <div className="section section-flush form-section">
        {createTicket.isError && (
          <div className="alert">
            {createTicket.error instanceof Error
              ? createTicket.error.message
              : "Could not create request"}
          </div>
        )}
        {attachError && <div className="alert">{attachError}</div>}

        {ticket ? (
          <UploadStep
            publicId={ticket.public_id}
            ttNumber={ticket.tt_number}
            serviceId={String(ticket.service?.id ?? form.state.values.service_id)}
            requisitionId={String(
              ticket.requisition?.id ?? form.state.values.requisition_id
            )}
            journey={intent || "subscribe"}
          />
        ) : (
          <div className="panel form-panel journey-panel">
            {!intent ? (
              <div className="journey-chooser">
                <div className="form-panel-head">
                  <h2>What do you want to do?</h2>
                  <p className="muted">
                    Pick one path. You can switch later if you chose the wrong one.
                  </p>
                </div>
                <div className="intent-grid">
                  <button
                    type="button"
                    className="intent-card intent-card-subscribe"
                    onClick={() => chooseIntent("subscribe")}
                  >
                    <span className="intent-kicker">Journey A</span>
                    <strong>New subscription</strong>
                    <p>
                      First-time activation. You will only see services you can still
                      subscribe to.
                    </p>
                    <span className="intent-cta">Continue →</span>
                  </button>
                  <button
                    type="button"
                    className="intent-card intent-card-manage"
                    onClick={() => chooseIntent("manage")}
                    disabled={!subsLoading && !servicesLoading && !canManage}
                  >
                    <span className="intent-kicker">Journey B</span>
                    <strong>Manage service</strong>
                    <p>
                      Changes on an active subscription, or requests for services that
                      do not require a subscription.
                    </p>
                    {!subsLoading && !servicesLoading && !canManage ? (
                      <span className="intent-cta muted">Nothing available to manage yet</span>
                    ) : (
                      <span className="intent-cta">
                        {[
                          aliveSubs.length
                            ? `${aliveSubs.length} subscription${aliveSubs.length === 1 ? "" : "s"}`
                            : null,
                          manageOneOffServices.length
                            ? `${manageOneOffServices.length} non-subscription`
                            : null,
                        ]
                          .filter(Boolean)
                          .join(" · ") || "Continue"}
                        {" · Continue →"}
                      </span>
                    )}
                  </button>
                </div>
                {!subsLoading && !servicesLoading && !canManage && (
                  <p className="journey-hint muted">
                    Tip: start with <strong>New subscription</strong> for subscription-based
                    products. Non-subscription services appear here under Manage.
                  </p>
                )}
              </div>
            ) : (
              <form
                onSubmit={(e) => {
                  e.preventDefault();
                  e.stopPropagation();
                  void form.handleSubmit();
                }}
                noValidate
              >
                <div className="journey-top">
                  <div>
                    <span className="intent-kicker">
                      {intent === "subscribe" ? "Journey A · Subscribe" : "Journey B · Manage"}
                    </span>
                    <h2 className="journey-step-title">
                      {intent === "subscribe"
                        ? ["Select service", "Review & submit"][step] || "Documents"
                        : ["Select service", "Choose change", "Review & submit"][step] ||
                          "Documents"}
                    </h2>
                  </div>
                  <button type="button" className="linkish" onClick={resetIntent}>
                    Switch journey
                  </button>
                </div>

                <ol className="journey-steps" aria-label="Progress">
                  {labels.slice(0, -1).map((label, i) => (
                    <li
                      key={label}
                      className={
                        i < step ? "is-done" : i === step ? "is-active" : undefined
                      }
                    >
                      <i>{i + 1}</i>
                      <span>{label}</span>
                    </li>
                  ))}
                </ol>

                {(servicesLoading || subsLoading || !deepLinkReady) && (
                  <p className="muted">Loading your catalog…</p>
                )}

                {/* —— Subscribe steps —— */}
                {intent === "subscribe" && step === 0 && deepLinkReady && (
                  <div className="journey-body">
                    <p className="muted journey-lead">
                      Choose the VAS product to activate. Services you already
                      subscribe to (or have pending) are hidden.
                    </p>
                    {!subscribeServices.length ? (
                      <div className="empty">
                        No services are available to activate right now.
                      </div>
                    ) : (
                      <div className="journey-option-list" role="listbox">
                        {subscribeServices.map((s) => {
                          const selected = String(s.id) === String(serviceId);
                          return (
                            <button
                              key={s.id}
                              type="button"
                              role="option"
                              aria-selected={selected}
                              className={`journey-option${selected ? " is-selected" : ""}`}
                              onClick={() => {
                                if (selected) {
                                  setStep(1);
                                  return;
                                }
                                form.setFieldValue("service_id", String(s.id));
                                const starters = starterRequisitions(s);
                                form.setFieldValue(
                                  "requisition_id",
                                  starters.length === 1 ? String(starters[0].id) : ""
                                );
                              }}
                              onDoubleClick={() => {
                                form.setFieldValue("service_id", String(s.id));
                                const starters = starterRequisitions(s);
                                form.setFieldValue(
                                  "requisition_id",
                                  starters.length === 1 ? String(starters[0].id) : ""
                                );
                                setStep(1);
                              }}
                            >
                              <strong>{s.name}</strong>
                              <span>
                                {s.renewal_interval === "bi_yearly"
                                  ? "Bi-yearly renewal"
                                  : "Yearly renewal"}
                                {s.category?.name ? ` · ${s.category.name}` : ""}
                              </span>
                            </button>
                          );
                        })}
                      </div>
                    )}
                    <div className="form-actions">
                      <button
                        type="button"
                        className="btn-primary"
                        disabled={!serviceId}
                        onClick={() => setStep(1)}
                      >
                        Continue
                      </button>
                      <button type="button" className="btn-ghost" onClick={resetIntent}>
                        Back
                      </button>
                    </div>
                  </div>
                )}

                {intent === "subscribe" && step === 1 && (
                  <div className="journey-body">
                    <dl className="journey-summary">
                      <div>
                        <dt>Service</dt>
                        <dd>{selectedSubscribe?.name || "—"}</dd>
                      </div>
                      <div>
                        <dt>Request type</dt>
                        <dd>
                          {starterTypes.find((r) => String(r.id) === String(requisitionId))
                            ?.name || "New subscription"}
                        </dd>
                      </div>
                    </dl>
                    <form.Field name="description">
                      {(field) => (
                        <div className="field field-span">
                          <label htmlFor={field.name}>
                            Description <span className="req">*</span>
                          </label>
                          <textarea
                            id={field.name}
                            rows={4}
                            value={field.state.value}
                            onBlur={field.handleBlur}
                            onChange={(e) => field.handleChange(e.target.value)}
                            placeholder="Briefly describe why you need this service"
                          />
                        </div>
                      )}
                    </form.Field>
                    <RequirementsPreview
                      serviceId={String(serviceId || "")}
                      requisitionId={String(requisitionId || "")}
                      files={stagedFiles}
                      onFilesChange={setStagedFiles}
                    />
                    <div className="form-actions">
                      <button
                        type="submit"
                        className="btn-primary"
                        disabled={
                          createTicket.isPending ||
                          uploadingDocs ||
                          !serviceId ||
                          !requisitionId ||
                          !description.trim() ||
                          !attachmentsReady
                        }
                      >
                        {createTicket.isPending || uploadingDocs
                          ? uploadingDocs
                            ? "Uploading documents…"
                            : "Creating…"
                          : "Submit subscription request"}
                      </button>
                      <button type="button" className="btn-ghost" onClick={() => setStep(0)}>
                        Back
                      </button>
                    </div>
                  </div>
                )}

                {/* —— Manage steps —— */}
                {intent === "manage" && step === 0 && deepLinkReady && (
                  <div className="journey-body">
                    <p className="muted journey-lead">
                      Pick an active subscription to change, or a service that does not
                      require a subscription.
                    </p>
                    {!canManage ? (
                      <div className="empty">
                        <p style={{ margin: "0 0 0.75rem" }}>
                          Nothing is available to manage right now.
                        </p>
                        <button
                          type="button"
                          className="btn-primary"
                          onClick={() => chooseIntent("subscribe")}
                        >
                          Start a new subscription
                        </button>
                      </div>
                    ) : (
                      <div className="journey-option-list">
                        {aliveSubs.map((s) => {
                          const selected = String(s.id) === String(subscriptionId);
                          return (
                            <button
                              key={`sub-${s.id}`}
                              type="button"
                              className={`journey-option${selected ? " is-selected" : ""}`}
                              onClick={() => {
                                if (selected) {
                                  setStep(1);
                                  return;
                                }
                                form.setFieldValue("subscription_id", String(s.id));
                                const sid = String(s.service?.id ?? s.service_id ?? "");
                                form.setFieldValue("service_id", sid);
                                form.setFieldValue("requisition_id", "");
                              }}
                              onDoubleClick={() => {
                                form.setFieldValue("subscription_id", String(s.id));
                                const sid = String(s.service?.id ?? s.service_id ?? "");
                                form.setFieldValue("service_id", sid);
                                form.setFieldValue("requisition_id", "");
                                setStep(1);
                              }}
                            >
                              <strong>
                                {s.service?.name || `Service #${s.service_id}`}
                              </strong>
                              <span>
                                Subscription · {s.status}
                                {s.current_period_end
                                  ? ` · Period ends ${new Date(s.current_period_end).toLocaleDateString()}`
                                  : ""}
                              </span>
                            </button>
                          );
                        })}
                        {manageOneOffServices.map((s) => {
                          const selected =
                            !subscriptionId && String(s.id) === String(serviceId);
                          return (
                            <button
                              key={`svc-${s.id}`}
                              type="button"
                              className={`journey-option${selected ? " is-selected" : ""}`}
                              onClick={() => {
                                if (selected) {
                                  setStep(1);
                                  return;
                                }
                                form.setFieldValue("subscription_id", "");
                                form.setFieldValue("service_id", String(s.id));
                                const types = manageRequisitions(s);
                                form.setFieldValue(
                                  "requisition_id",
                                  types.length === 1 ? String(types[0].id) : ""
                                );
                              }}
                              onDoubleClick={() => {
                                form.setFieldValue("subscription_id", "");
                                form.setFieldValue("service_id", String(s.id));
                                const types = manageRequisitions(s);
                                form.setFieldValue(
                                  "requisition_id",
                                  types.length === 1 ? String(types[0].id) : ""
                                );
                                setStep(1);
                              }}
                            >
                              <strong>{s.name}</strong>
                              <span>
                                No subscription required
                                {s.category?.name ? ` · ${s.category.name}` : ""}
                              </span>
                            </button>
                          );
                        })}
                      </div>
                    )}
                    {canManage && (
                      <div className="form-actions">
                        <button
                          type="button"
                          className="btn-primary"
                          disabled={!(subscriptionId || serviceId)}
                          onClick={() => setStep(1)}
                        >
                          Continue
                        </button>
                        <button type="button" className="btn-ghost" onClick={resetIntent}>
                          Back
                        </button>
                      </div>
                    )}
                  </div>
                )}

                {intent === "manage" && step === 1 && (
                  <div className="journey-body">
                    <p className="muted journey-lead">
                      What do you need on{" "}
                      <strong>{selectedManage?.name || "this service"}</strong>?
                    </p>
                    {!manageTypes.length ? (
                      <div className="empty">
                        No request types are enabled for this service.
                      </div>
                    ) : (
                      <div className="journey-option-list">
                        {manageTypes.map((r) => {
                          const selected = String(r.id) === String(requisitionId);
                          const pending = pendingFor(manageServiceId, Number(r.id));
                          return (
                            <button
                              key={r.id}
                              type="button"
                              className={`journey-option${selected ? " is-selected" : ""}${pending ? " is-disabled" : ""}`}
                              disabled={!!pending}
                              title={
                                pending
                                  ? `Already open: ${pending.tt_number}. Close it before submitting another.`
                                  : undefined
                              }
                              onClick={() => {
                                if (pending) return;
                                if (selected) {
                                  setStep(2);
                                  return;
                                }
                                form.setFieldValue("requisition_id", String(r.id));
                              }}
                              onDoubleClick={() => {
                                if (pending) return;
                                form.setFieldValue("requisition_id", String(r.id));
                                setStep(2);
                              }}
                            >
                              <strong>{r.name}</strong>
                              <span>
                                {pending
                                  ? `In progress — ${pending.tt_number}. Open that request instead.`
                                  : managingOneOff
                                    ? "Non-subscription request"
                                    : r.terminates_subscription
                                      ? "Ends the subscription"
                                      : r.renews_subscription
                                        ? "Extends the subscription period"
                                        : "Requires an active subscription"}
                              </span>
                            </button>
                          );
                        })}
                      </div>
                    )}
                    <div className="form-actions">
                      <button
                        type="button"
                        className="btn-primary"
                        disabled={!requisitionId}
                        onClick={() => setStep(2)}
                      >
                        Continue
                      </button>
                      <button type="button" className="btn-ghost" onClick={() => setStep(0)}>
                        Back
                      </button>
                    </div>
                  </div>
                )}

                {intent === "manage" && step === 2 && (
                  <div className="journey-body">
                    <dl className="journey-summary">
                      <div>
                        <dt>Service</dt>
                        <dd>{selectedManage?.name || "—"}</dd>
                      </div>
                      <div>
                        <dt>{managingOneOff ? "Request type" : "Change type"}</dt>
                        <dd>
                          {manageTypes.find((r) => String(r.id) === String(requisitionId))
                            ?.name || "—"}
                        </dd>
                      </div>
                    </dl>
                    <form.Field name="description">
                      {(field) => (
                        <div className="field field-span">
                          <label htmlFor={field.name}>
                            Description <span className="req">*</span>
                          </label>
                          <textarea
                            id={field.name}
                            rows={4}
                            value={field.state.value}
                            onBlur={field.handleBlur}
                            onChange={(e) => field.handleChange(e.target.value)}
                            placeholder={
                              managingOneOff
                                ? "Describe what you need"
                                : "Describe the change or issue"
                            }
                          />
                        </div>
                      )}
                    </form.Field>
                    <RequirementsPreview
                      serviceId={manageServiceId ? String(manageServiceId) : ""}
                      requisitionId={String(requisitionId || "")}
                      files={stagedFiles}
                      onFilesChange={setStagedFiles}
                    />
                    <div className="form-actions">
                      <button
                        type="submit"
                        className="btn-primary"
                        disabled={
                          createTicket.isPending ||
                          uploadingDocs ||
                          !serviceId ||
                          !requisitionId ||
                          (!managingOneOff && !subscriptionId) ||
                          !description.trim() ||
                          !attachmentsReady
                        }
                      >
                        {createTicket.isPending || uploadingDocs
                          ? uploadingDocs
                            ? "Uploading documents…"
                            : "Creating…"
                          : managingOneOff
                            ? "Submit request"
                            : "Submit management request"}
                      </button>
                      <button type="button" className="btn-ghost" onClick={() => setStep(1)}>
                        Back
                      </button>
                    </div>
                  </div>
                )}
              </form>
            )}
          </div>
        )}
      </div>
    </>
  );
}

function UploadStep({
  publicId,
  ttNumber,
  serviceId,
  requisitionId,
  journey,
}: {
  publicId: string;
  ttNumber: string;
  serviceId: string;
  requisitionId: string;
  journey: string;
}) {
  const { data: ticket } = useTicket(publicId);
  const { data: requirements = [] } = useDocumentRequirements(serviceId, requisitionId);
  const requiredIds = requirements
    .filter((r) => {
      if (!r.is_required) return false;
      // "Document if any" is attachable but must not block Finish.
      if (r.document_type.code === "document-if-any") return false;
      if (/if any/i.test(r.document_type.name)) return false;
      return true;
    })
    .map((r) => r.document_type.id);
  const uploadedTypes = new Set(
    (ticket?.documents || [])
      .map((d) => d.document_type_id ?? d.document_type?.id)
      .filter(Boolean)
  );
  const allRequiredUploaded = requiredIds.every((id) => uploadedTypes.has(id));
  const locked = documentsLockedStatus(ticket?.status, ticket?.documents_locked);

  const panelTicket: Ticket = {
    public_id: publicId,
    tt_number: ttNumber,
    status: ticket?.status ?? "open",
    documents_locked: ticket?.documents_locked ?? false,
    documents: ticket?.documents ?? [],
    service: ticket?.service ?? { id: Number(serviceId) || 0, name: "" },
    requisition: ticket?.requisition ?? { id: Number(requisitionId) || 0, name: "" },
    created_at: ticket?.created_at ?? "",
    id: ticket?.id ?? 0,
  };

  return (
    <div className="panel form-panel">
      <div className="form-panel-head">
        <span className="intent-kicker">
          {journey === "manage" ? "Journey B" : "Journey A"} · Documents
        </span>
        <h2>Upload documents</h2>
        <p className="muted">
          Request <strong>{ttNumber}</strong> is open. Attach the files listed for this request
          type
          {requiredIds.length ? " (required ones marked with *)" : ""}.
        </p>
      </div>

      <TicketDocumentsPanel
        ticket={panelTicket}
        mode="wizard"
        serviceId={serviceId}
        requisitionId={requisitionId}
      />

      <div className="form-actions">
        <Link
          href={`/portal/requests/${publicId}`}
          className="btn-primary"
          style={{
            pointerEvents:
              locked || allRequiredUploaded || !requiredIds.length ? "auto" : "none",
            opacity: locked || allRequiredUploaded || !requiredIds.length ? 1 : 0.5,
          }}
          onClick={(e) => {
            if (!(locked || allRequiredUploaded || !requiredIds.length)) e.preventDefault();
          }}
        >
          Finish
        </Link>
        <Link href={`/portal/requests/${publicId}`} className="btn-ghost">
          Skip for now
        </Link>
      </div>
    </div>
  );
}
