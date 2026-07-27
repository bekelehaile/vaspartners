"use client";

import { JourneyLaunchActions, PortalPageHeader } from "@/components/PortalPageHeader";
import { RequestsTable } from "@/components/RequestsTable";

export default function PortalHomePage() {
  return (
    <>
      <PortalPageHeader
        title="Service requests"
        description="Track and open requests for your current company. Filter by status or service, then start a new subscription or manage an existing service."
        actions={<JourneyLaunchActions />}
      />

      <div className="section section-flush">
        <RequestsTable />
      </div>
    </>
  );
}
