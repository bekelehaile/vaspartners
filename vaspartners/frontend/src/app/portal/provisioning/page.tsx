"use client";

import { PortalPageHeader } from "@/components/PortalPageHeader";
import { ProvisioningTable } from "@/components/ProvisioningTable";

export default function ProvisioningListPage() {
  return (
    <>
      <PortalPageHeader title="Service activation" />
      <div className="section section-flush">
        <ProvisioningTable />
      </div>
    </>
  );
}
