"use client";

import { useMemo } from "react";
import { useQueries } from "@tanstack/react-query";
import { api } from "@/lib/api";
import type { DocumentRequirement } from "@/lib/api";
import { queryKeys } from "@/lib/query-keys";

export function ServiceRequirements({
  serviceId,
  requisitionIds,
}: {
  serviceId: number;
  requisitionIds: number[];
}) {
  const results = useQueries({
    queries: requisitionIds.map((requisitionId) => ({
      queryKey: queryKeys.catalog.documentRequirements(
        String(serviceId),
        String(requisitionId),
      ),
      queryFn: async () => {
        const res = await api<{ data: DocumentRequirement[] }>(
          `/document-requirements?service_id=${serviceId}&requisition_id=${requisitionId}`,
        );
        return res.data ?? [];
      },
      enabled: !!serviceId && !!requisitionId,
    })),
  });

  const loading = results.some((r) => r.isLoading);
  const errored = results.some((r) => r.isError);

  const merged = useMemo(() => {
    const byType = new Map<
      number,
      { id: number; name: string; required: boolean; description?: string | null }
    >();
    for (const result of results) {
      for (const req of result.data ?? []) {
        const typeId = req.document_type.id;
        const existing = byType.get(typeId);
        if (!existing) {
          byType.set(typeId, {
            id: req.id,
            name: req.document_type.name,
            required: !!req.is_required,
            description: req.document_type.description,
          });
        } else if (req.is_required) {
          existing.required = true;
        }
      }
    }
    return Array.from(byType.values()).sort((a, b) => a.name.localeCompare(b.name));
  }, [results]);

  if (requisitionIds.length === 0) {
    return (
      <p className="muted" style={{ marginTop: "0.35rem" }}>
        No requirements configured for this service yet.
      </p>
    );
  }

  if (loading) {
    return (
      <p className="muted" style={{ marginTop: "0.35rem" }}>
        Loading requirements…
      </p>
    );
  }

  if (errored && merged.length === 0) {
    return (
      <p className="muted" style={{ marginTop: "0.35rem" }}>
        Could not load requirements for this service.
      </p>
    );
  }

  if (merged.length === 0) {
    return (
      <p className="muted" style={{ marginTop: "0.35rem" }}>
        No documents required for this service.
      </p>
    );
  }

  return (
    <ul className="service-req-list">
      {merged.map((req) => (
        <li key={req.id}>
          <strong>{req.name}</strong>
          {req.required ? (
            <span className="service-req-badge is-required">Required</span>
          ) : (
            <span className="service-req-badge">Optional</span>
          )}
          {req.description?.trim() ? (
            <p className="muted">{req.description.trim()}</p>
          ) : null}
        </li>
      ))}
    </ul>
  );
}
