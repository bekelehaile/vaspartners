"use client";

import { MyCompanyRequestsPanel } from "@/components/CompanyRequestsInbox";
import { OrganisationRequestsNav } from "@/components/OrganisationRequestsNav";
import { PortalPageHeader } from "@/components/PortalPageHeader";
import { useContact } from "@/hooks/use-contact";

export default function CompanyRequestsPage() {
  const { data: me } = useContact();
  const membershipDisabled =
    !!me?.company_id && me?.company_membership_active === false;
  const enabled = !!me && !membershipDisabled;

  return (
    <>
      <PortalPageHeader
        kicker="Organisation"
        title="Company requests"
        description="Requests you submitted about a company: create or join, leave, transfer ownership, or company profile approval. This is not the same as membership requests waiting for your approval."
      />

      <div className="section section-flush">
        <OrganisationRequestsNav />
        {membershipDisabled ? (
          <div className="panel">
            <p className="muted" style={{ marginBottom: 0 }}>
              Your company membership is disabled, so company requests are not available.
            </p>
          </div>
        ) : (
          <MyCompanyRequestsPanel enabled={enabled} />
        )}
      </div>
    </>
  );
}
