import type { Metadata } from "next";
import { absoluteUrl, siteConfig } from "@/lib/site";

type BuildMetaInput = {
  title?: string;
  description?: string;
  path?: string;
  image?: string | null;
  noIndex?: boolean;
  type?: "website" | "article";
  publishedTime?: string | null;
};

export function buildPageMetadata({
  title,
  description = siteConfig.description,
  path = "/",
  image,
  noIndex = false,
  type = "website",
  publishedTime,
}: BuildMetaInput = {}): Metadata {
  const pageTitle = title
    ? { absolute: `${title} | VAS Partners · Ethio telecom` }
    : { absolute: siteConfig.defaultTitle };
  const url = absoluteUrl(path);
  const ogImage = image || absoluteUrl("/brand/ethio_logo_full.png");

  return {
    title: pageTitle,
    description,
    keywords: [...siteConfig.keywords],
    alternates: { canonical: url },
    openGraph: {
      type,
      locale: siteConfig.locale,
      url,
      siteName: `${siteConfig.name} · ${siteConfig.orgName}`,
      title: title || siteConfig.defaultTitle,
      description,
      images: [{ url: ogImage, alt: `${siteConfig.orgName} ${siteConfig.name}` }],
      ...(publishedTime ? { publishedTime } : {}),
    },
    twitter: {
      card: "summary_large_image",
      site: siteConfig.twitterHandle,
      title: title || siteConfig.defaultTitle,
      description,
      images: [ogImage],
    },
    robots: noIndex
      ? { index: false, follow: false, googleBot: { index: false, follow: false } }
      : { index: true, follow: true },
  };
}
