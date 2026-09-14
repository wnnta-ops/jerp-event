import type { Metadata } from "next";
import "./globals.css";
import { Providers } from "@/components/Providers";
import { ToastHost } from "@/components/Toast";

export const metadata: Metadata = {
  title: "Java Sales Hub — Internal System",
  description: "Sistem internal divisi sales. Input sekali, semua report otomatis.",
};

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="id">
      <head>
        <link
          rel="preconnect"
          href="https://fonts.googleapis.com"
        />
        <link
          href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap"
          rel="stylesheet"
        />
      </head>
      <body>
        <Providers>{children}</Providers>
        <ToastHost />
      </body>
    </html>
  );
}
