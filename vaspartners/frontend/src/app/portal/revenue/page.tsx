"use client";

import { JourneyLaunchActions, PortalPageHeader } from "@/components/PortalPageHeader";
import { PartnerRevenueTable } from "@/components/PartnerRevenueTable";

export default function PartnerRevenuePage() {
  return (
    <>
      <PortalPageHeader
        title="Revenue"
        description="Monthly revenue notified by Ethio telecom for your current company. Use this table if an SMS failed or you need the amounts again."
        actions={<JourneyLaunchActions />}
      />

      <div className="section section-flush">
        <PartnerRevenueTable />
      </div>
    </>
  );
}
