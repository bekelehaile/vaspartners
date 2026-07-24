import type { Metadata } from "next";
import { JsonLd } from "@/components/JsonLd";
import { LandingPage } from "@/components/LandingPage";
import { buildPageMetadata } from "@/lib/seo";
import { absoluteUrl, siteConfig } from "@/lib/site";

export const metadata: Metadata = buildPageMetadata({
  path: "/",
});

export default function HomePage() {
  const orgLd = {
    "@context": "https://schema.org",
    "@type": "Organization",
    name: siteConfig.orgName,
    url: "https://www.ethiotelecom.et/",
    logo: absoluteUrl("/brand/ethio_logo_full.png"),
    sameAs: [...siteConfig.sameAs],
  };

  const webLd = {
    "@context": "https://schema.org",
    "@type": "WebSite",
    name: `${siteConfig.name} · ${siteConfig.orgName}`,
    url: absoluteUrl("/"),
    description: siteConfig.description,
    publisher: {
      "@type": "Organization",
      name: siteConfig.orgName,
    },
    inLanguage: siteConfig.language,
  };

  return (
    <>
      <JsonLd data={[orgLd, webLd]} />
      <LandingPage />
    </>
  );
}
