import type { Metadata } from "next";
import { Poppins } from "next/font/google";
import "./globals.css";

const sans = Poppins({
  variable: "--font-sans",
  subsets: ["latin"],
  weight: ["300", "400", "500", "600", "700"],
});

export const metadata: Metadata = {
  title: "VAS Partners | Ethio Telecom",
  description: "Value Added Services partner portal — Ethio telecom",
};

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <html lang="en">
      <body className={`${sans.variable} font-sans antialiased`}>{children}</body>
    </html>
  );
}
