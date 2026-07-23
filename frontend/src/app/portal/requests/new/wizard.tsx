"use client";

import Link from "next/link";
import { useForm } from "@tanstack/react-form";
import { useState } from "react";
import { useSearchParams } from "next/navigation";
import { PortalPageHeader } from "@/components/PortalPageHeader";
import {
  useCreateTicket,
  useDocumentRequirements,
  useServices,
  useUploadTicketDocument,
} from "@/hooks/use-customer";
import type { Service } from "@/lib/api";
import { ticketCreateSchema } from "@/lib/schemas/ticket";

type Requisition = NonNullable<Service["requisitions"]>[number];

function fieldError(errors: unknown): string | null {
  if (!errors || !Array.isArray(errors) || errors.length === 0) return null;
  const first = errors[0];
  if (typeof first === "string") return first;
  if (first && typeof first === "object" && "message" in first) {
    return String((first as { message: unknown }).message);
  }
  return String(first);
}

export default function NewRequestWizard() {
  const params = useSearchParams();
  const presetService = params.get("service") || "";

  const { data: services = [] } = useServices();
  const createTicket = useCreateTicket();

  const form = useForm({
    defaultValues: {
      service_id: presetService,
      requisition_id: "",
      description: "",
    },
    validators: {
      onSubmit: ticketCreateSchema,
    },
    onSubmit: async ({ value }) => {
      await createTicket.mutateAsync(ticketCreateSchema.parse(value));
    },
  });

  const ticket = createTicket.data;

  return (
    <>
      <PortalPageHeader
        kicker="Service request"
        title="Submit a service request"
        description="Choose the VAS service and request type, then attach only the documents that apply."
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

        {ticket ? (
          <UploadStep
            publicId={ticket.public_id}
            ttNumber={ticket.tt_number}
            serviceId={String(ticket.service?.id ?? form.state.values.service_id)}
            requisitionId={String(ticket.requisition?.id ?? form.state.values.requisition_id)}
          />
        ) : (
          <form
            className="panel form-panel"
            onSubmit={(e) => {
              e.preventDefault();
              e.stopPropagation();
              void form.handleSubmit();
            }}
            noValidate
          >
            <div className="form-panel-head">
              <h2>Request details</h2>
              <p className="muted">
                Required fields must be completed before creating the request.
              </p>
            </div>

            <div className="form-grid">
              <form.Subscribe selector={(s) => s.values.service_id}>
                {(serviceId) => {
                  const selected = services.find((s) => String(s.id) === String(serviceId));
                  const requisitions: Requisition[] = selected?.requisitions ?? [];

                  return (
                    <>
                      <form.Field name="service_id">
                        {(field) => {
                          const err =
                            form.state.submissionAttempts > 0
                              ? fieldError(field.state.meta.errors)
                              : null;
                          return (
                            <div className={`field${err ? " has-error" : ""}`}>
                              <label htmlFor={field.name}>
                                Service <span className="req">*</span>
                              </label>
                              <select
                                id={field.name}
                                name={field.name}
                                value={field.state.value}
                                onBlur={field.handleBlur}
                                onChange={(e) => {
                                  field.handleChange(e.target.value);
                                  form.setFieldValue("requisition_id", "");
                                }}
                              >
                                <option value="">Select a service</option>
                                {services.map((s) => (
                                  <option key={s.id} value={s.id}>
                                    {s.name}
                                  </option>
                                ))}
                              </select>
                              {err && <p className="field-error">{err}</p>}
                            </div>
                          );
                        }}
                      </form.Field>

                      <form.Field name="requisition_id">
                        {(field) => {
                          const err =
                            form.state.submissionAttempts > 0
                              ? fieldError(field.state.meta.errors)
                              : null;
                          return (
                            <div className={`field${err ? " has-error" : ""}`}>
                              <label htmlFor={field.name}>
                                Request type <span className="req">*</span>
                              </label>
                              <select
                                id={field.name}
                                name={field.name}
                                value={field.state.value}
                                onBlur={field.handleBlur}
                                onChange={(e) => field.handleChange(e.target.value)}
                                disabled={!requisitions.length}
                              >
                                <option value="">Select request type</option>
                                {requisitions.map((r) => (
                                  <option key={r.id} value={r.id}>
                                    {r.name}
                                  </option>
                                ))}
                              </select>
                              {!serviceId && <small>Choose a service first</small>}
                              {serviceId && !requisitions.length && (
                                <small>No request types are enabled for this service yet.</small>
                              )}
                              {err && <p className="field-error">{err}</p>}
                            </div>
                          );
                        }}
                      </form.Field>

                      <form.Subscribe selector={(s) => s.values.requisition_id}>
                        {(requisitionId) => (
                          <div className="field-span">
                            <RequirementsPreview
                              serviceId={serviceId}
                              requisitionId={requisitionId}
                            />
                          </div>
                        )}
                      </form.Subscribe>
                    </>
                  );
                }}
              </form.Subscribe>

              <form.Field name="description">
                {(field) => {
                  const err =
                    form.state.submissionAttempts > 0
                      ? fieldError(field.state.meta.errors)
                      : null;
                  return (
                    <div className={`field field-span${err ? " has-error" : ""}`}>
                      <label htmlFor={field.name}>
                        Description <span className="req">*</span>
                      </label>
                      <textarea
                        id={field.name}
                        name={field.name}
                        rows={4}
                        value={field.state.value}
                        onBlur={field.handleBlur}
                        onChange={(e) => field.handleChange(e.target.value)}
                        placeholder="Describe what you need and any relevant details"
                        aria-invalid={!!err}
                        aria-describedby={err ? `${field.name}-error` : undefined}
                      />
                      {err && (
                        <p id={`${field.name}-error`} className="field-error" role="alert">
                          {err}
                        </p>
                      )}
                    </div>
                  );
                }}
              </form.Field>
            </div>

            <form.Subscribe
              selector={(s) => [
                s.values.service_id,
                s.values.requisition_id,
                s.values.description,
                s.isSubmitting,
              ]}
            >
              {([serviceId, requisitionId, description, isSubmitting]) => (
                <div className="form-actions">
                  <button
                    type="submit"
                    className="btn-primary"
                    disabled={
                      !!isSubmitting ||
                      createTicket.isPending ||
                      !serviceId ||
                      !requisitionId ||
                      !String(description || "").trim()
                    }
                  >
                    {isSubmitting || createTicket.isPending ? "Creating…" : "Create request"}
                  </button>
                  <Link href="/portal" className="btn-ghost">
                    Cancel
                  </Link>
                </div>
              )}
            </form.Subscribe>
          </form>
        )}
      </div>
    </>
  );
}

function RequirementsPreview({
  serviceId,
  requisitionId,
}: {
  serviceId: string;
  requisitionId: string;
}) {
  const { data: requirements = [] } = useDocumentRequirements(serviceId, requisitionId);
  if (!requirements.length) return null;

  return (
    <div className="field doc-preview">
      <label>Documents you will need</label>
      <ul>
        {requirements.map((r) => (
          <li key={r.id}>
            {r.document_type.name}
            {r.is_required ? " (required)" : " (optional)"}
          </li>
        ))}
      </ul>
    </div>
  );
}

function UploadStep({
  publicId,
  ttNumber,
  serviceId,
  requisitionId,
}: {
  publicId: string;
  ttNumber: string;
  serviceId: string;
  requisitionId: string;
}) {
  const { data: requirements = [] } = useDocumentRequirements(serviceId, requisitionId);
  const upload = useUploadTicketDocument(publicId);
  const [uploads, setUploads] = useState<Record<number, string>>({});

  const requiredIds = requirements.filter((r) => r.is_required).map((r) => r.document_type.id);
  const allRequiredUploaded = requiredIds.every((id) => uploads[id]);

  return (
    <div className="panel form-panel">
      <div className="form-panel-head">
        <h2>Upload documents</h2>
        <p className="muted">
          Request <strong>{ttNumber}</strong> is open. Attach the files below
          {requiredIds.length ? " (required ones marked)" : ""}.
        </p>
      </div>

      {upload.isError && (
        <div className="alert">
          {upload.error instanceof Error ? upload.error.message : "Upload failed"}
        </div>
      )}

      {!requirements.length ? (
        <div className="empty">No documents are required for this request type.</div>
      ) : (
        <div className="upload-grid">
          {requirements.map((r) => (
            <div key={r.id} className={`doc-slot${uploads[r.document_type.id] ? " is-done" : ""}`}>
              <label>
                {r.document_type.name}
                {r.is_required ? " *" : ""}
              </label>
              {uploads[r.document_type.id] ? (
                <small style={{ color: "var(--et-green)" }}>
                  Uploaded: {uploads[r.document_type.id]}
                </small>
              ) : (
                <input
                  type="file"
                  accept={r.document_type.accepted_mimes
                    .split(",")
                    .map((m) => `.${m.trim()}`)
                    .join(",")}
                  disabled={upload.isPending}
                  onChange={(e) => {
                    const file = e.target.files?.[0];
                    if (!file) return;
                    upload.mutate(
                      { documentTypeId: r.document_type.id, file },
                      {
                        onSuccess: (result) => {
                          setUploads((prev) => ({
                            ...prev,
                            [result.documentTypeId]: result.fileName,
                          }));
                        },
                      }
                    );
                  }}
                />
              )}
            </div>
          ))}
        </div>
      )}

      <div className="form-actions">
        <Link
          href={`/portal/requests/${publicId}`}
          className="btn-primary"
          style={{
            pointerEvents: allRequiredUploaded || !requiredIds.length ? "auto" : "none",
            opacity: allRequiredUploaded || !requiredIds.length ? 1 : 0.5,
          }}
          onClick={(e) => {
            if (!(allRequiredUploaded || !requiredIds.length)) e.preventDefault();
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
