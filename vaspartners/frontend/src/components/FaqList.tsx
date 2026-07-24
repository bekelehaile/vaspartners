"use client";

import Link from "next/link";
import { useFaqs } from "@/hooks/use-customer";

export function FaqList({
  limit,
  emptyMessage = "FAQ content will appear here once published by Ethio telecom.",
}: {
  limit?: number;
  emptyMessage?: string;
}) {
  const { data: faqs = [], isLoading } = useFaqs();
  const visible = typeof limit === "number" ? faqs.slice(0, limit) : faqs;

  if (isLoading) {
    return <p className="muted">Loading FAQ…</p>;
  }

  if (!visible.length) {
    return <p className="muted">{emptyMessage}</p>;
  }

  return (
    <div className="faq-list">
      {visible.map((f) => (
        <details key={f.id} className="faq-item">
          <summary>{f.question}</summary>
          <p>{f.answer}</p>
        </details>
      ))}
    </div>
  );
}

export function LandingFaqSection() {
  const { data: faqs = [], isLoading } = useFaqs();

  if (!isLoading && !faqs.length) return null;

  return (
    <section className="section" id="faq">
      <span className="section-label">FAQ</span>
      <h2>Frequently asked questions</h2>
      <p className="section-lead">Common VAS partner questions from Ethio telecom.</p>
      <FaqList limit={6} />
      {faqs.length > 0 && (
        <div className="section-more">
          <Link href="/faq" className="linkish">
            View all FAQ →
          </Link>
        </div>
      )}
    </section>
  );
}
