"use client";

import { MembershipRequestsPanel } from "@/components/CompanyRequestsInbox";
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
        description="Partners asking to join a company you own (or were granted approval rights for). Approve or reject here."
      />

      <div className="section section-flush">
        {membershipDisabled ? (
          <div className="data-table-card">
            <div className="portal-empty">
              <p>
                Your company membership is disabled, so membership requests are not available.
              </p>
            </div>
          </div>
        ) : (
          <MembershipRequestsPanel enabled={enabled} />
        )}
      </div>
    </>
  );
}
