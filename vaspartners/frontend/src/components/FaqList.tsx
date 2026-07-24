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
    <section className="section" id="faq" aria-labelledby="faq-heading">
      <header className="section-head">
        <span className="section-label">Support</span>
        <h2 id="faq-heading">Frequently asked questions</h2>
        <p className="section-lead">
          Common questions about partner onboarding and service requests.
        </p>
      </header>
      {isLoading ? <p className="muted">Loading FAQ…</p> : <FaqList limit={6} />}
      <div className="section-more">
        <Link href="/faq" className="linkish">
          View all FAQs
        </Link>
      </div>
    </section>
  );
}
