"use client";

import { PortalPageHeader } from "@/components/PortalPageHeader";
import { ServicesCatalog } from "@/components/ServicesCatalog";

export default function ServicesPage() {
  return (
    <>
      <PortalPageHeader title="My services" />
      <ServicesCatalog compact className="section section-flush" />
    </>
  );
}
