"use client";

import type {
  ChangeEvent,
  InputHTMLAttributes,
  TextareaHTMLAttributes,
} from "react";
import { useForm } from "@tanstack/react-form";
import { useRouter } from "next/navigation";
import { Contact } from "@/lib/api";
import { FaydaIdentityPanel } from "@/components/FaydaIdentityPanel";
import {
  CompanyProfileValues,
  companyProfileSchema,
  emptyCompanyProfile,
} from "@/lib/schemas/company";
import { useCompleteCompanyProfile } from "@/hooks/use-contact";

function fieldError(errors: unknown): string | null {
  if (!errors || !Array.isArray(errors) || errors.length === 0) return null;
  const first = errors[0];
  if (typeof first === "string") return first;
  if (first && typeof first === "object" && "message" in first) {
    return String((first as { message: unknown }).message);
  }
  return String(first);
}

function fromContact(me?: Contact | null, createNew = false): CompanyProfileValues {
  if (!me || createNew) return emptyCompanyProfile;
  return {
    company_name: me.company_name ?? "",
    company_tin: me.company_tin ?? "",
    company_address: me.company_address ?? "",
  };
}

type FieldApi = {
  name: string;
  state: {
    value: string;
    meta: {
      errors: unknown;
      isTouched: boolean;
      isBlurred: boolean;
    };
  };
  handleBlur: () => void;
  handleChange: (value: string) => void;
};

function CompanyField({
  field,
  label,
  submissionAttempts,
  as = "input",
  ...inputProps
}: {
  field: FieldApi;
  label: string;
  submissionAttempts: number;
  as?: "input" | "textarea";
} & InputHTMLAttributes<HTMLInputElement> &
  TextareaHTMLAttributes<HTMLTextAreaElement>) {
  const show =
    field.state.meta.isTouched || field.state.meta.isBlurred || submissionAttempts > 0;
  const err = show ? fieldError(field.state.meta.errors) : null;
  const onChange = (e: ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) =>
    field.handleChange(e.target.value);

  return (
    <div className={`field${as === "textarea" ? " field-span" : ""}${err ? " has-error" : ""}`}>
      <label htmlFor={field.name}>
        {label} <span className="req">*</span>
      </label>
      {as === "textarea" ? (
        <textarea
          id={field.name}
          name={field.name}
          value={field.state.value}
          onBlur={field.handleBlur}
          onChange={onChange}
          rows={3}
          aria-invalid={!!err || undefined}
          aria-describedby={err ? `${field.name}-error` : undefined}
          {...(inputProps as TextareaHTMLAttributes<HTMLTextAreaElement>)}
        />
      ) : (
        <input
          id={field.name}
          name={field.name}
          value={field.state.value}
          onBlur={field.handleBlur}
          onChange={onChange}
          aria-invalid={!!err || undefined}
          aria-describedby={err ? `${field.name}-error` : undefined}
          {...(inputProps as InputHTMLAttributes<HTMLInputElement>)}
        />
      )}
      {err && (
        <p id={`${field.name}-error`} className="field-error">
          {err}
        </p>
      )}
    </div>
  );
}

export function CompanyProfileForm({
  me,
  redirectTo = "/portal",
  createNew = false,
}: {
  me?: Contact | null;
  redirectTo?: string;
  createNew?: boolean;
}) {
  const router = useRouter();
  const mutation = useCompleteCompanyProfile();
  const isUpdate = !!me?.company_id && !createNew;

  const form = useForm({
    defaultValues: fromContact(me, createNew),
    validators: {
      onChange: companyProfileSchema,
      onSubmit: companyProfileSchema,
    },
    onSubmit: async ({ value }) => {
      const parsed = companyProfileSchema.parse(value);
      await mutation.mutateAsync({ ...parsed, create_new: createNew || undefined });
      router.replace(redirectTo);
    },
  });

  return (
    <form
      className="panel company-form"
      onSubmit={(e) => {
        e.preventDefault();
        e.stopPropagation();
        void form.handleSubmit();
      }}
      noValidate
    >
      <div className="company-form-head">
        <p className="brand-kicker">{isUpdate ? "Settings" : "Required once"}</p>
        <h2>{isUpdate ? "Organisation settings" : "Company / organisation profile"}</h2>
        <p className="muted">
          {isUpdate
            ? "Update your company details and resubmit for admin approval. After approval, only administrators can change company records."
            : createNew
              ? "Register another company with a unique TIN. Your signed-in phone and email are used as company contact. You stay owner of your other companies."
              : "Submit organisation details for admin approval. Your signed-in phone and email are used as company contact. Each company needs its own unique TIN."}
        </p>
      </div>

      {me && (
        <FaydaIdentityPanel
          id="fayda-identity"
          title="Your identity"
          description="Phone and email from your account are applied to this company automatically. Contact support if anything is wrong."
          person={me}
          badge={
            me.company_role === "owner" ? (
              <span className="service-meta">Company owner</span>
            ) : undefined
          }
        />
      )}

      {mutation.isError && (
        <div className="alert" role="alert">
          {mutation.error instanceof Error
            ? mutation.error.message
            : "Could not save company details"}
        </div>
      )}

      <form.Subscribe selector={(s) => s.submissionAttempts}>
        {(submissionAttempts) => (
          <section id="company-info" className="settings-block">
            <div className="settings-block-head">
              <h3>Company profile</h3>
              <p className="muted">
                Organisation name, unique TIN, and address. Phone{" "}
                {me?.phone_number ? (
                  <>
                    <strong>{me.phone_number}</strong>
                  </>
                ) : (
                  "from your account"
                )}{" "}
                is used for this company.
              </p>
            </div>
            <div className="form-grid">
              <form.Field name="company_name">
                {(field) => (
                  <CompanyField
                    field={field}
                    label="Company / organisation name"
                    submissionAttempts={submissionAttempts}
                    placeholder="e.g. Sunrise Media PLC"
                    autoComplete="organization"
                  />
                )}
              </form.Field>

              <form.Field name="company_tin">
                {(field) => (
                  <CompanyField
                    field={field}
                    label="TIN"
                    submissionAttempts={submissionAttempts}
                    placeholder="e.g. 00012345"
                    inputMode="numeric"
                    autoComplete="off"
                  />
                )}
              </form.Field>

              <form.Field name="company_address">
                {(field) => (
                  <CompanyField
                    field={field}
                    label="Company address"
                    submissionAttempts={submissionAttempts}
                    as="textarea"
                    placeholder="City, sub-city, woreda / street"
                    autoComplete="street-address"
                  />
                )}
              </form.Field>
            </div>
          </section>
        )}
      </form.Subscribe>

      <form.Subscribe selector={(s) => s.isSubmitting}>
        {(isSubmitting) => (
          <div className="form-actions">
            <button
              type="submit"
              className="btn-primary"
              disabled={isSubmitting || mutation.isPending}
            >
              {isSubmitting || mutation.isPending
                ? "Saving…"
                : isUpdate
                  ? "Save changes"
                  : "Save and continue"}
            </button>
            <p className="muted form-hint">All fields marked * are required.</p>
          </div>
        )}
      </form.Subscribe>
    </form>
  );
}
