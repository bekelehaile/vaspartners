"use client";

import { JourneyLaunchActions, PortalPageHeader } from "@/components/PortalPageHeader";
import { RequestsTable } from "@/components/RequestsTable";

export default function PortalHomePage() {
  return (
    <>
      <PortalPageHeader
        kicker="Partner portal"
        title="Service requests"
        description="Company service requests for your current organisation. Track progress, open any member’s request, and start a new subscription or manage an existing service."
        actions={<JourneyLaunchActions />}
      />

      <div className="section section-flush">
        <RequestsTable />
      </div>
    </>
  );
}
