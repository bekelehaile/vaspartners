"use client";

import { NewRequestButton, PortalPageHeader } from "@/components/PortalPageHeader";
import { RequestsTable } from "@/components/RequestsTable";

export default function RequestsPage() {
  return (
    <>
      <PortalPageHeader
        kicker="Partner portal"
        title="My service requests"
        description="Search and filter your VAS orders. Open any row for progress, documents, and comments."
        actions={<NewRequestButton />}
      />

      <div className="section section-flush">
        <RequestsTable />
      </div>
    </>
  );
}
