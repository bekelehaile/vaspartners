"use client";

import type {
  ChangeEvent,
  InputHTMLAttributes,
  TextareaHTMLAttributes,
} from "react";
import { useForm } from "@tanstack/react-form";
import { useRouter } from "next/navigation";
import { Customer } from "@/lib/api";
import {
  CompanyProfileValues,
  companyProfileSchema,
  emptyCompanyProfile,
} from "@/lib/schemas/company";
import { useCompleteCompanyProfile } from "@/hooks/use-customer";

function fieldError(errors: unknown): string | null {
  if (!errors || !Array.isArray(errors) || errors.length === 0) return null;
  const first = errors[0];
  if (typeof first === "string") return first;
  if (first && typeof first === "object" && "message" in first) {
    return String((first as { message: unknown }).message);
  }
  return String(first);
}

function fromCustomer(me?: Customer | null): CompanyProfileValues {
  if (!me) return emptyCompanyProfile;
  return {
    company_name: me.company_name ?? "",
    company_tin: me.company_tin ?? "",
    company_phone: me.company_phone ?? "",
    company_email: me.company_email ?? "",
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
}: {
  me?: Customer | null;
  redirectTo?: string;
}) {
  const router = useRouter();
  const mutation = useCompleteCompanyProfile();
  const isUpdate = !!me?.profile_completed;

  const form = useForm({
    defaultValues: fromCustomer(me),
    validators: {
      onChange: companyProfileSchema,
      onSubmit: companyProfileSchema,
    },
    onSubmit: async ({ value }) => {
      const parsed = companyProfileSchema.parse(value);
      await mutation.mutateAsync(parsed);
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
            ? "Keep company details current. Your Fayda national ID identity below cannot be changed here."
            : "Fayda verified your national ID. Complete your organisation details before you can submit VAS service requests."}
        </p>
      </div>

      {me && (
        <section id="fayda-identity" className="settings-block fayda-readonly">
          <div className="settings-block-head">
            <h3>Fayda identity</h3>
            <p className="muted">From National ID — read-only. Contact Fayda support if this is wrong.</p>
          </div>
          <dl className="fayda-dl">
            <div>
              <dt>Full name</dt>
              <dd>{me.name || "—"}</dd>
            </div>
            <div>
              <dt>Phone</dt>
              <dd>{me.phone_number || "—"}</dd>
            </div>
            <div>
              <dt>Email</dt>
              <dd>{me.email || "—"}</dd>
            </div>
            <div>
              <dt>Gender</dt>
              <dd>{me.gender || "—"}</dd>
            </div>
            <div>
              <dt>Nationality</dt>
              <dd>{me.nationality || "—"}</dd>
            </div>
            <div>
              <dt>Birthdate</dt>
              <dd>{me.birthdate || "—"}</dd>
            </div>
          </dl>
        </section>
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
          <>
            <section id="company-info" className="settings-block">
              <div className="settings-block-head">
                <h3>Company info</h3>
                <p className="muted">Organisation identity and address.</p>
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
                      placeholder="Tax identification number"
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

            <section id="contact-info" className="settings-block">
              <div className="settings-block-head">
                <h3>Company contact</h3>
                <p className="muted">Organisation phone and email (not your Fayda personal identity).</p>
              </div>
              <div className="form-grid">
                <form.Field name="company_phone">
                  {(field) => (
                    <CompanyField
                      field={field}
                      label="Company phone"
                      submissionAttempts={submissionAttempts}
                      type="tel"
                      placeholder="e.g. +251 11 xxx xxxx"
                      autoComplete="tel"
                    />
                  )}
                </form.Field>

                <form.Field name="company_email">
                  {(field) => (
                    <CompanyField
                      field={field}
                      label="Company email"
                      submissionAttempts={submissionAttempts}
                      type="email"
                      placeholder="ops@company.et"
                      autoComplete="email"
                    />
                  )}
                </form.Field>
              </div>
            </section>
          </>
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
