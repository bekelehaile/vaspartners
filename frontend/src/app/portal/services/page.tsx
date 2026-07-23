"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { SiteShell } from "@/components/SiteShell";
import { Customer, Service, api, clearToken, getToken } from "@/lib/api";

export default function ServicesPage() {
  const router = useRouter();
  const [me, setMe] = useState<Customer | null>(null);
  const [services, setServices] = useState<Service[]>([]);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!getToken()) {
      router.replace("/");
      return;
    }
    api<{ data: Customer }>("/auth/me").then((r) => setMe(r.data)).catch(() => router.replace("/"));
    api<{ data: Service[] }>("/services")
      .then((r) => setServices(r.data ?? []))
      .catch((e) => setError(e.message));
  }, [router]);

  const logout = async () => {
    try {
      await api("/auth/logout", { method: "POST" });
    } catch {
      /* ignore */
    }
    clearToken();
    router.replace("/");
  };

  return (
    <SiteShell me={me} onLogout={logout} compact>
      <div className="portal-hero">
        <h1>Services</h1>
        <p className="muted">Choose what you need. We will ask only for the documents that apply.</p>
      </div>
      <div className="section" style={{ paddingTop: 0 }}>
        {error && <div className="alert">{error}</div>}
        <div className="panel">
          <div className="service-list">
            {services.map((s) => (
              <div key={s.id} className="service-item">
                <div>
                  <strong>{s.name}</strong>
                  <p>{s.description || s.category?.name || "Value added service"}</p>
                </div>
                <Link
                  href={`/portal/requests/new?service=${s.id}`}
                  className="btn-primary"
                  style={{ padding: "0.55rem 1rem", whiteSpace: "nowrap" }}
                >
                  Request
                </Link>
              </div>
            ))}
            {!services.length && !error && (
              <div className="empty">Services will appear here once published by the VAS team.</div>
            )}
          </div>
        </div>
      </div>
    </SiteShell>
  );
}
