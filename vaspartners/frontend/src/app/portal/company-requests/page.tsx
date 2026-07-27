"use client";

import { MyCompanyRequestsPanel } from "@/components/CompanyRequestsInbox";
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
        description="Requests you submitted about a company: create or join, leave, transfer ownership, or company profile approval."
      />

      <div className="section section-flush">
        {membershipDisabled ? (
          <div className="data-table-card">
            <div className="portal-empty">
              <p>
                Your company membership is disabled, so company requests are not available.
              </p>
            </div>
          </div>
        ) : (
          <MyCompanyRequestsPanel enabled={enabled} />
        )}
      </div>
    </>
  );
}
