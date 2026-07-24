"use client";

import { JourneyLaunchActions, PortalPageHeader } from "@/components/PortalPageHeader";
import { RequestsTable } from "@/components/RequestsTable";

export default function PortalHomePage() {
  return (
    <>
      <PortalPageHeader
        kicker="Partner portal"
        title="My service requests"
        description="Track orders below. Start a new subscription or manage a service you already have."
        actions={<JourneyLaunchActions />}
      />

      <div className="section section-flush">
        <RequestsTable />
      </div>
    </>
  );
}
