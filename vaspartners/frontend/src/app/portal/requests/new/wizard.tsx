"use client";

import Link from "next/link";
import { useForm, useStore } from "@tanstack/react-form";
import { useEffect, useMemo, useState } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { PortalPageHeader } from "@/components/PortalPageHeader";
import {
  useContact,
  useCreateTicket,
  useDocumentRequirements,
  useServices,
  useSubscriptions,
  uploadTicketDocumentFile,
} from "@/hooks/use-contact";
import type { Service, Subscription } from "@/lib/api";
import { contactCanCreateSubscriptions, contactCanManageServices } from "@/lib/company-permissions";
import { ticketCreateSchema } from "@/lib/schemas/ticket";
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
  const creators = (service.requisitions ?? []).filter((r) => !!r.creates_subscription);
  if (!creators.length) return [];

  // Prefer the partner-facing onboarding type. Extra creates_subscription links
  // (Revenue Request, Additional Services, …) are internal/alternate paths and
  // must not leave requisition_id empty — that hides the attachment uploader.
  const rank = (name: string): number => {
    const n = name.trim().toLowerCase();
    if (n === "new subscription") return 0;
    if (n === "additional services") return 1;
    if (n === "revenue request") return 2;
    if (n.includes("merchant")) return 3;
    return 50;
  };

  const bestRank = Math.min(...creators.map((r) => rank(String(r.name || ""))));
  const pool = creators.filter((r) => rank(String(r.name || "")) === bestRank);

  return [...pool].sort((a, b) => a.id - b.id);
}

function primaryStarterRequisitionId(service: Service): string {
  const starters = starterRequisitions(service);
  return starters.length ? String(starters[0].id) : "";
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
    ? ["Service", "Confirm"]
    : ["Service", "Change type", "Confirm"];
}

export default function NewRequestWizard() {
  const router = useRouter();
  const params = useSearchParams();
  const presetService = params.get("service") || "";
  const presetSubscription = params.get("subscription_id") || "";
  const presetIntentParam = params.get("intent");
  const presetIntent: Intent | "" =
    presetIntentParam === "subscribe" || presetIntentParam === "manage"
      ? presetIntentParam
      : "";

  const { data: me, isLoading: meLoading } = useContact();
  const { data: services = [], isLoading: servicesLoading } = useServices();
  const { data: subscriptionData, isLoading: subsLoading } = useSubscriptions();
  const createTicket = useCreateTicket();
  const canSubscribe = !me || contactCanCreateSubscriptions(me);
  const canManageJourney = !me || contactCanManageServices(me);
  const canCreate = canSubscribe || canManageJourney;
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
  const [approverPopup, setApproverPopup] = useState<string | null>(null);

  const isApproverMissingError = (err: unknown): boolean =>
    err instanceof Error && /approver is not found/i.test(err.message);

  const form = useForm({
    defaultValues: {
      intent: presetIntent as Intent | "",
      service_id: presetService,
      requisition_id: "",
      subscription_id: "",
      category_id: "",
      description: "",
    },
    onSubmit: async ({ value }) => {
      setAttachError(null);
      setApproverPopup(null);
      const parsed = ticketCreateSchema.parse(value);
      let created;
      try {
        created = await createTicket.mutateAsync(parsed);
      } catch (err) {
        if (isApproverMissingError(err) && err instanceof Error) {
          setApproverPopup(err.message);
        }
        throw err;
      }

      const entries = Object.entries(stagedFiles);
      if (entries.length) {
        setUploadingDocs(true);
        try {
          for (const [documentTypeId, file] of entries) {
            await uploadTicketDocumentFile(created.tt_number, Number(documentTypeId), file);
          }
          setStagedFiles({});
        } catch (err) {
          // Request already exists — open it so the partner can finish docs or delete.
          setAttachError(
            err instanceof Error
              ? err.message
              : "Request created, but some documents failed to upload.",
          );
        } finally {
          setUploadingDocs(false);
        }
      }

      router.push(`/portal/requests/${created.tt_number}`);
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
    isSuccess: confirmDocsSuccess,
    isError: confirmDocsError,
  } = useDocumentRequirements(confirmServiceId, String(requisitionId || ""));
  // Never treat a failed/empty load as "ready" — SMS Premium / VISP / etc. would skip uploads.
  const attachmentsReady =
    (!!confirmServiceId && !!requisitionId
      ? confirmDocsSuccess && !confirmDocsLoading && !confirmDocsError
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

  // Auto-pick primary starter requisition for subscribe path (e.g. "New subscription")
  useEffect(() => {
    if (intent !== "subscribe" || !serviceId) return;
    if (!starterTypes.length) return;
    const primary = String(starterTypes[0].id);
    if (requisitionId !== primary) {
      form.setFieldValue("requisition_id", primary);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps -- selection only
  }, [intent, serviceId, starterTypes.length, starterTypes[0]?.id, requisitionId]);

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
          form.setFieldValue("requisition_id", primaryStarterRequisitionId(svc));
          setStep(1);
        }
      } else if (subscribeServices.length === 1) {
        const only = subscribeServices[0];
        form.setFieldValue("service_id", String(only.id));
        form.setFieldValue("requisition_id", primaryStarterRequisitionId(only));
      }
    }

    if (presetIntent === "manage") {
      if (presetSubscription) {
        const sub = aliveSubs.find((s) => String(s.id) === presetSubscription);
        if (sub) {
          form.setFieldValue("subscription_id", String(sub.id));
          form.setFieldValue(
            "service_id",
            String(sub.service?.id ?? sub.service_id ?? "")
          );
          setStep(1);
        }
      } else if (presetService) {
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
    presetSubscription,
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
    form.setFieldValue("category_id", "");
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
    form.setFieldValue("category_id", "");
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

      {!meLoading && me && !canCreate ? (
        <div className="section section-flush">
          <div className="panel">
            <p className="muted" style={{ marginBottom: 0 }}>
              You do not have permission to start subscriptions or manage services for this
              company. Ask the company owner to grant <strong>New VAS subscriptions</strong>{" "}
              and/or <strong>Manage service</strong>, or open existing company requests from
              the Service requests list.
            </p>
            <p style={{ marginTop: "1rem", marginBottom: 0 }}>
              <Link href="/portal" className="btn-primary">
                View company requests
              </Link>{" "}
              <Link href="/portal/subscriptions" className="btn-ghost">
                View subscriptions
              </Link>
            </p>
          </div>
        </div>
      ) : !meLoading &&
        me &&
        ((intent === "subscribe" && !canSubscribe) ||
          (intent === "manage" && !canManageJourney)) ? (
        <div className="section section-flush">
          <div className="panel">
            <p className="muted" style={{ marginBottom: 0 }}>
              {intent === "subscribe" ? (
                <>
                  You do not have permission for <strong>New VAS subscriptions</strong>. Ask
                  your company owner to grant it, or use Manage service if you have that
                  access.
                </>
              ) : (
                <>
                  You do not have permission to <strong>Manage service</strong>. Ask your
                  company owner to grant it, or start a new subscription if you have that
                  access.
                </>
              )}
            </p>
            <p style={{ marginTop: "1rem", marginBottom: 0 }}>
              <Link href="/portal/requests/new" className="btn-primary">
                Choose another journey
              </Link>{" "}
              <Link href="/portal" className="btn-ghost">
                Back to requests
              </Link>
            </p>
          </div>
        </div>
      ) : (
      <div className="section section-flush form-section">
        {createTicket.isError && !isApproverMissingError(createTicket.error) && (
          <div className="alert" role="alert">
            {createTicket.error instanceof Error
              ? createTicket.error.message
              : "Could not create request"}
          </div>
        )}
        {attachError && <div className="alert">{attachError}</div>}

        {approverPopup && (
          <div
            className="portal-modal-backdrop"
            role="presentation"
            onClick={() => {
              setApproverPopup(null);
              createTicket.reset();
            }}
          >
            <div
              className="portal-modal"
              role="alertdialog"
              aria-modal="true"
              aria-labelledby="approver-missing-title"
              aria-describedby="approver-missing-desc"
              onClick={(e) => e.stopPropagation()}
            >
              <h2 id="approver-missing-title">Next approver is not found</h2>
              <p id="approver-missing-desc">{approverPopup}</p>
              <p className="portal-modal-hint">
                Ask an administrator to set a final approver for this service and
                request type (for example SMS Premium → Maintenance), then try again.
              </p>
              <div className="portal-modal-actions">
                <button
                  type="button"
                  className="btn"
                  onClick={() => {
                    setApproverPopup(null);
                    createTicket.reset();
                  }}
                >
                  OK
                </button>
              </div>
            </div>
          </div>
        )}

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
                  {canSubscribe && (
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
                  )}
                  {canManageJourney && (
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
                  )}
                </div>
                {canSubscribe && !subsLoading && !servicesLoading && !canManage && (
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
                        ? ["Select service", "Review & submit"][step] || "Review & submit"
                        : ["Select service", "Choose change", "Review & submit"][step] ||
                          "Review & submit"}
                    </h2>
                  </div>
                  <button type="button" className="linkish" onClick={resetIntent}>
                    Switch journey
                  </button>
                </div>

                <ol className="journey-steps" aria-label="Progress">
                  {labels.map((label, i) => (
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
                      <div
                        className="journey-option-list journey-option-list-cols"
                        role="listbox"
                        aria-label="Services available to subscribe"
                      >
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
                                form.setFieldValue(
                                  "requisition_id",
                                  primaryStarterRequisitionId(s),
                                );
                              }}
                              onDoubleClick={() => {
                                form.setFieldValue("service_id", String(s.id));
                                form.setFieldValue(
                                  "requisition_id",
                                  primaryStarterRequisitionId(s),
                                );
                                setStep(1);
                              }}
                            >
                              <strong>{s.name}</strong>
                              <span>
                                {s.renewal_interval === "bi_yearly"
                                  ? "Bi-yearly renewal"
                                  : "Yearly renewal"}
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
                          : "Submit"}
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
                      <div className="journey-option-list journey-option-list-cols">
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
                              <span>No subscription required</span>
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
                                  ? `Already open — request number ${pending.tt_number}. Close it before submitting another.`
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
                                  ? `In progress — request number ${pending.tt_number}. Open that request instead.`
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
      </div>
      )}
    </>
  );
}
