"use client";

import { CompanyProfileForm } from "@/components/CompanyProfileForm";
import { useCustomer } from "@/hooks/use-customer";

export default function CompanyProfilePage() {
  const { data: me } = useCustomer();
  const isUpdate = !!me?.profile_completed;

  return (
    <>
      <div className="portal-hero">
        <p className="brand-kicker">{isUpdate ? "Organisation" : "Welcome"}</p>
        <h1>
          {isUpdate
            ? "Company / organisation profile"
            : "Complete your organisation profile"}
        </h1>
        <p className="muted">
          {isUpdate
            ? `Update details for ${me?.company_name || "your organisation"}.`
            : `Hello${me?.name ? `, ${me.name.split(" ")[0]}` : ""}. Your national ID is verified — we still need your company details to process partner requests.`}
        </p>
      </div>

      <div className="section company-section">
        <CompanyProfileForm
          key={me?.public_id ?? "company-form"}
          me={me}
          redirectTo="/portal"
        />
      </div>
    </>
  );
}
