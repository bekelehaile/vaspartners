"use client";

import Link from "next/link";
import { FormEvent, useEffect, useMemo, useState } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { SiteShell } from "@/components/SiteShell";
import {
  Customer,
  DocumentRequirement,
  Service,
  Ticket,
  api,
  clearToken,
  getToken,
} from "@/lib/api";

type Requisition = NonNullable<Service["requisitions"]>[number];

export default function NewRequestWizard() {
  const router = useRouter();
  const params = useSearchParams();
  const presetService = params.get("service");

  const [me, setMe] = useState<Customer | null>(null);
  const [services, setServices] = useState<Service[]>([]);
  const [serviceId, setServiceId] = useState<string>(presetService || "");
  const [requisitionId, setRequisitionId] = useState("");
  const [description, setDescription] = useState("");
  const [building, setBuilding] = useState("");
  const [location, setLocation] = useState("");
  const [requirements, setRequirements] = useState<DocumentRequirement[]>([]);
  const [ticket, setTicket] = useState<Ticket | null>(null);
  const [uploads, setUploads] = useState<Record<number, string>>({});
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    if (!getToken()) {
      router.replace("/");
      return;
    }
    (async () => {
      try {
        const meRes = await api<{ data: Customer }>("/auth/me");
        setMe(meRes.data);
        if (!meRes.data.profile_completed) {
          router.replace("/portal");
          return;
        }
        const sRes = await api<{ data: Service[] }>("/services");
        setServices(sRes.data ?? []);
      } catch {
        clearToken();
        router.replace("/");
      }
    })();
  }, [router]);

  const selectedService = useMemo(
    () => services.find((s) => String(s.id) === String(serviceId)),
    [services, serviceId]
  );

  const requisitions: Requisition[] = selectedService?.requisitions ?? [];

  useEffect(() => {
    setRequisitionId("");
    setRequirements([]);
    setTicket(null);
    setUploads({});
  }, [serviceId]);

  useEffect(() => {
    if (!serviceId || !requisitionId) {
      setRequirements([]);
      return;
    }
    api<{ data: DocumentRequirement[] }>(
      `/document-requirements?service_id=${serviceId}&requisition_id=${requisitionId}`
    )
      .then((r) => setRequirements(r.data ?? []))
      .catch((e) => setError(e.message));
  }, [serviceId, requisitionId]);

  const logout = async () => {
    try {
      await api("/auth/logout", { method: "POST" });
    } catch {
      /* ignore */
    }
    clearToken();
    router.replace("/");
  };

  const createTicket = async (e: FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      const res = await api<{ data: Ticket }>("/tickets", {
        method: "POST",
        body: JSON.stringify({
          service_id: Number(serviceId),
          requisition_id: Number(requisitionId),
          description: description || null,
          building: building || null,
          location: location || null,
        }),
      });
      setTicket(res.data);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Could not create request");
    } finally {
      setBusy(false);
    }
  };

  const uploadFile = async (documentTypeId: number, file: File) => {
    if (!ticket) return;
    setBusy(true);
    setError(null);
    try {
      const body = new FormData();
      body.append("document_type_id", String(documentTypeId));
      body.append("file", file);
      await api(`/tickets/${ticket.public_id}/documents`, { method: "POST", body });
      setUploads((u) => ({ ...u, [documentTypeId]: file.name }));
    } catch (err) {
      setError(err instanceof Error ? err.message : "Upload failed");
    } finally {
      setBusy(false);
    }
  };

  const requiredIds = requirements.filter((r) => r.is_required).map((r) => r.document_type.id);
  const allRequiredUploaded = requiredIds.every((id) => uploads[id]);

  return (
    <SiteShell me={me} onLogout={logout} compact>
      <div className="portal-hero">
        <p className="brand-kicker">New request</p>
        <h1>Tell us what you need</h1>
        <p className="muted">Pick the service and request type. We only ask for documents that apply.</p>
      </div>

      <div className="section" style={{ paddingTop: 0 }}>
        {error && <div className="alert">{error}</div>}

        {!ticket ? (
          <form className="panel" onSubmit={createTicket}>
            <div className="field">
              <label htmlFor="service">Service</label>
              <select
                id="service"
                value={serviceId}
                onChange={(e) => setServiceId(e.target.value)}
                required
              >
                <option value="">Select a service</option>
                {services.map((s) => (
                  <option key={s.id} value={s.id}>
                    {s.name}
                  </option>
                ))}
              </select>
            </div>

            <div className="field">
              <label htmlFor="requisition">Request type</label>
              <select
                id="requisition"
                value={requisitionId}
                onChange={(e) => setRequisitionId(e.target.value)}
                required
                disabled={!requisitions.length}
              >
                <option value="">Select request type</option>
                {requisitions.map((r) => (
                  <option key={r.id} value={r.id}>
                    {r.name}
                  </option>
                ))}
              </select>
              {!serviceId && <small>Choose a service first</small>}
              {serviceId && !requisitions.length && (
                <small>No request types are enabled for this service yet.</small>
              )}
            </div>

            <div className="field">
              <label htmlFor="building">Building / site (optional)</label>
              <input id="building" value={building} onChange={(e) => setBuilding(e.target.value)} />
            </div>

            <div className="field">
              <label htmlFor="location">Location notes (optional)</label>
              <input id="location" value={location} onChange={(e) => setLocation(e.target.value)} />
            </div>

            <div className="field">
              <label htmlFor="description">Description (optional)</label>
              <textarea
                id="description"
                rows={4}
                value={description}
                onChange={(e) => setDescription(e.target.value)}
              />
            </div>

            {requirements.length > 0 && (
              <div className="field">
                <label>Documents you will need</label>
                <ul style={{ margin: 0, paddingLeft: "1.1rem", color: "var(--et-muted)" }}>
                  {requirements.map((r) => (
                    <li key={r.id}>
                      {r.document_type.name}
                      {r.is_required ? " (required)" : " (optional)"}
                    </li>
                  ))}
                </ul>
              </div>
            )}

            <div style={{ display: "flex", gap: "0.75rem", flexWrap: "wrap" }}>
              <button className="btn-primary" disabled={busy || !serviceId || !requisitionId}>
                {busy ? "Creating…" : "Create request"}
              </button>
              <Link href="/portal" className="btn-ghost">
                Cancel
              </Link>
            </div>
          </form>
        ) : (
          <div className="panel">
            <h2>Upload documents</h2>
            <p className="muted" style={{ marginTop: "-0.5rem" }}>
              Request <strong>{ticket.tt_number}</strong> is open. Attach the files below
              {requiredIds.length ? " (required ones marked)" : ""}.
            </p>

            {!requirements.length ? (
              <div className="empty">No documents are required for this request type.</div>
            ) : (
              <div style={{ display: "grid", gap: "1rem" }}>
                {requirements.map((r) => (
                  <div key={r.id} className="field">
                    <label>
                      {r.document_type.name}
                      {r.is_required ? " *" : ""}
                    </label>
                    {uploads[r.document_type.id] ? (
                      <small style={{ color: "var(--et-leaf)" }}>
                        Uploaded: {uploads[r.document_type.id]}
                      </small>
                    ) : (
                      <input
                        type="file"
                        accept={r.document_type.accepted_mimes
                          .split(",")
                          .map((m) => `.${m.trim()}`)
                          .join(",")}
                        disabled={busy}
                        onChange={(e) => {
                          const file = e.target.files?.[0];
                          if (file) void uploadFile(r.document_type.id, file);
                        }}
                      />
                    )}
                  </div>
                ))}
              </div>
            )}

            <div style={{ marginTop: "1.25rem", display: "flex", gap: "0.75rem", flexWrap: "wrap" }}>
              <Link
                href={`/portal/requests/${ticket.public_id}`}
                className="btn-primary"
                style={{ pointerEvents: allRequiredUploaded || !requiredIds.length ? "auto" : "none", opacity: allRequiredUploaded || !requiredIds.length ? 1 : 0.5 }}
                onClick={(e) => {
                  if (!(allRequiredUploaded || !requiredIds.length)) e.preventDefault();
                }}
              >
                Finish
              </Link>
              <Link href={`/portal/requests/${ticket.public_id}`} className="btn-ghost">
                Skip for now
              </Link>
            </div>
          </div>
        )}
      </div>
    </SiteShell>
  );
}
