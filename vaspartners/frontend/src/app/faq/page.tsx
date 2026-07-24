import { JsonLd } from "@/components/JsonLd";
import { FaqPageView } from "@/components/FaqPageView";
import { fetchPublishedFaqs } from "@/lib/public-content";
import { absoluteUrl } from "@/lib/site";

export default async function FaqPage() {
  const faqs = await fetchPublishedFaqs();

  const faqLd =
    faqs.length > 0
      ? {
          "@context": "https://schema.org",
          "@type": "FAQPage",
          mainEntity: faqs.map((f) => ({
            "@type": "Question",
            name: f.question,
            acceptedAnswer: {
              "@type": "Answer",
              text: f.answer,
            },
          })),
          url: absoluteUrl("/faq"),
        }
      : null;

  return (
    <>
      {faqLd && <JsonLd data={faqLd} />}
      <FaqPageView />
    </>
  );
}
