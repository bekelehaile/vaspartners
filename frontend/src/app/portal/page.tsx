"use client";

import Link from "next/link";
import { NewRequestButton, PortalPageHeader } from "@/components/PortalPageHeader";
import { RequestsTable } from "@/components/RequestsTable";
import { useCustomer } from "@/hooks/use-customer";

export default function PortalHomePage() {
  const { data: me } = useCustomer();

  return (
    <>
      <PortalPageHeader
        kicker="Partner portal"
        title={`Hello${me?.name ? `, ${me.name.split(" ")[0]}` : ""}`}
        description={`Signed in with Fayda for ${me?.company_name || "your organisation"}. Track requests below or start a new one.`}
        actions={<NewRequestButton />}
      />

      <div className="portal-grid">
        <section className="panel panel-flush">
          <div className="panel-toolbar">
            <h2>Recent requests</h2>
            <Link href="/portal/requests" className="linkish">
              View all
            </Link>
          </div>
          <RequestsTable compact />
        </section>

        <aside className="panel">
          <h2>{me?.company_name || "Your company"}</h2>
          <dl className="company-dl">
            <div>
              <dt>Contact</dt>
              <dd>{me?.name}</dd>
              <dd>{me?.phone_number || "—"}</dd>
            </div>
            <div>
              <dt>Organisation</dt>
              {me?.company_tin && <dd>TIN: {me.company_tin}</dd>}
              {me?.company_phone && <dd>{me.company_phone}</dd>}
              {me?.company_email && <dd>{me.company_email}</dd>}
              {me?.company_address && <dd>{me.company_address}</dd>}
            </div>
          </dl>
          <div className="panel-actions">
            <Link href="/portal/services" className="btn-ghost">
              Browse services
            </Link>
            <Link href="/portal/company" className="linkish">
              Update company profile
            </Link>
          </div>
        </aside>
      </div>
    </>
  );
}
