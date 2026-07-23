"use client";

import Link from "next/link";
import { useMemo } from "react";
import { useServices } from "@/hooks/use-customer";

export default function ServicesPage() {
  const { data: services = [], error, isError } = useServices();

  const grouped = useMemo(() => {
    const map = new Map<string, typeof services>();
    for (const s of services) {
      const key = s.category?.name || "Other services";
      if (!map.has(key)) map.set(key, []);
      map.get(key)!.push(s);
    }
    return Array.from(map.entries());
  }, [services]);

  return (
    <>
      <div className="portal-hero">
        <h1>Services</h1>
        <p className="muted">
          Browse by category and service type. We only ask for the documents that apply to your
          request.
        </p>
      </div>
      <div className="section" style={{ paddingTop: 0 }}>
        {isError && (
          <div className="alert">
            {error instanceof Error ? error.message : "Unable to load services"}
          </div>
        )}
        {grouped.map(([category, rows]) => (
          <div key={category} className="category-block panel">
            <h2>{category}</h2>
            <div className="service-list">
              {rows.map((s) => (
                <div key={s.id} className="service-item">
                  <div>
                    <strong>{s.name}</strong>
                    {s.type && <span className="service-meta">{s.type}</span>}
                    <p>{s.description || "Value added service"}</p>
                  </div>
                  <Link
                    href={`/portal/requests/new?intent=${
                      s.is_subscription_based === false ? "manage" : "subscribe"
                    }&service=${s.id}`}
                    className="btn-primary"
                    style={{ padding: "0.55rem 1rem", whiteSpace: "nowrap" }}
                  >
                    Request
                  </Link>
                </div>
              ))}
            </div>
          </div>
        ))}
        {!services.length && !isError && (
          <div className="panel">
            <div className="empty">Services will appear here once published by the VAS team.</div>
          </div>
        )}
      </div>
    </>
  );
}
