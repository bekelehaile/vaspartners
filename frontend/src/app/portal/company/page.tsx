"use client";

import { CompanyProfileForm } from "@/components/CompanyProfileForm";
import { PortalPageHeader } from "@/components/PortalPageHeader";
import { useCustomer } from "@/hooks/use-customer";

export default function CompanyProfilePage() {
  const { data: me } = useCustomer();
  const isUpdate = !!me?.profile_completed;

  return (
    <>
      <PortalPageHeader
        kicker={isUpdate ? "Settings" : "Welcome"}
        title={
          isUpdate
            ? "Company & contact info"
            : "Complete your organisation profile"
        }
        description={
          isUpdate
            ? `Update details for ${me?.company_name || "your organisation"}.`
            : `Hello${me?.name ? `, ${me.name.split(" ")[0]}` : ""}. Your national ID is verified — we still need your company and contact details to process partner requests.`
        }
      />

      <div className="section company-section section-flush">
        <CompanyProfileForm
          key={me?.public_id ?? "company-form"}
          me={me}
          redirectTo="/portal"
        />
      </div>
    </>
  );
}
