"use client";

import { JourneyLaunchActions, PortalPageHeader } from "@/components/PortalPageHeader";
import { SubscriptionsTable } from "@/components/SubscriptionsTable";

export default function SubscriptionsPage() {
  return (
    <>
      <PortalPageHeader
        kicker="Partner portal"
        title="Subscriptions"
        description="Company subscription history for your current organisation — active, renewing, and ended."
        actions={<JourneyLaunchActions />}
      />

      <div className="section section-flush">
        <SubscriptionsTable />
      </div>
    </>
  );
}
