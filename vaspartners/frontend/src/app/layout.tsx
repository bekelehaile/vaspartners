import type { Metadata } from "next";
import localFont from "next/font/local";
import { QueryProvider } from "@/providers/query-provider";
import { absoluteUrl, getSiteUrl, siteConfig } from "@/lib/site";
import { cn } from "@/lib/utils";
import "./globals.css";

/** Local Poppins — avoids Google Fonts fetch during Docker builds. */
const sans = localFont({
  variable: "--font-sans",
  display: "swap",
  src: [
    { path: "../fonts/poppins/Poppins-300.woff2", weight: "300", style: "normal" },
    { path: "../fonts/poppins/Poppins-400.woff2", weight: "400", style: "normal" },
    { path: "../fonts/poppins/Poppins-500.woff2", weight: "500", style: "normal" },
    { path: "../fonts/poppins/Poppins-600.woff2", weight: "600", style: "normal" },
    { path: "../fonts/poppins/Poppins-700.woff2", weight: "700", style: "normal" },
  ],
});

export const metadata: Metadata = {
  metadataBase: new URL(getSiteUrl()),
  title: {
    default: siteConfig.defaultTitle,
    template: siteConfig.titleTemplate,
  },
  description: siteConfig.description,
  applicationName: siteConfig.name,
  keywords: [...siteConfig.keywords],
  authors: [{ name: siteConfig.orgName }],
  creator: siteConfig.orgName,
  publisher: siteConfig.orgName,
  category: "business",
  formatDetection: {
    email: false,
    address: false,
    telephone: false,
  },
  alternates: {
    canonical: absoluteUrl("/"),
  },
  openGraph: {
    type: "website",
    locale: siteConfig.locale,
    url: absoluteUrl("/"),
    siteName: `${siteConfig.name} · ${siteConfig.orgName}`,
    title: siteConfig.defaultTitle,
    description: siteConfig.description,
    images: [
      {
        url: absoluteUrl("/brand/ethio_logo_full.png"),
        alt: `${siteConfig.orgName} logo`,
      },
    ],
  },
  twitter: {
    card: "summary_large_image",
    site: siteConfig.twitterHandle,
    title: siteConfig.defaultTitle,
    description: siteConfig.description,
    images: [absoluteUrl("/brand/ethio_logo_full.png")],
  },
  robots: {
    index: true,
    follow: true,
    googleBot: {
      index: true,
      follow: true,
      "max-image-preview": "large",
      "max-snippet": -1,
      "max-video-preview": -1,
    },
  },
  icons: {
    icon: [
      { url: "/favicon.ico", sizes: "any" },
      { url: "/brand/etlogo.png", type: "image/png" },
    ],
    shortcut: ["/favicon.ico"],
    apple: [{ url: "/brand/etlogo.png", type: "image/png" }],
  },
};

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <html lang={siteConfig.language} className={cn("font-sans", sans.variable)}>
      <body className={`${sans.variable} font-sans antialiased`}>
        <QueryProvider>{children}</QueryProvider>
      </body>
    </html>
  );
}
