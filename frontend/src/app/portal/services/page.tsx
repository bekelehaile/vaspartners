"use client";

import { PortalPageHeader } from "@/components/PortalPageHeader";
import { ServicesCatalog } from "@/components/ServicesCatalog";

export default function ServicesPage() {
  return (
    <>
      <PortalPageHeader
        kicker="Catalog"
        title="My services"
        description="Expand a service for its description and document requirements, then submit a request for your approved company."
      />
      <ServicesCatalog
        compact
        className="section section-flush"
        lead="Browse by category. Expand a service for details, then submit a request for your approved company."
      />
    </>
  );
}
