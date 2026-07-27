"use client";

import { MembershipRequestsPanel } from "@/components/CompanyRequestsInbox";
import { OrganisationRequestsNav } from "@/components/OrganisationRequestsNav";
import { PortalPageHeader } from "@/components/PortalPageHeader";
import { useContact } from "@/hooks/use-contact";

export default function MembershipRequestsPage() {
  const { data: me } = useContact();
  const membershipDisabled =
    !!me?.company_id && me?.company_membership_active === false;
  const enabled = !!me && !membershipDisabled;

  return (
    <>
      <PortalPageHeader
        kicker="Organisation"
        title="Membership requests"
        description="Partners asking to join a company you own (or were granted approval rights for). Approve or reject here. Your own company submissions are under Company requests."
      />

      <div className="section section-flush">
        <OrganisationRequestsNav />
        {membershipDisabled ? (
          <div className="panel">
            <p className="muted" style={{ marginBottom: 0 }}>
              Your company membership is disabled, so membership requests are not available.
            </p>
          </div>
        ) : (
          <MembershipRequestsPanel enabled={enabled} />
        )}
      </div>
    </>
  );
}
