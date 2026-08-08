import type { Metadata } from "next";
import "./globals.css";
import { AppHeader } from "@/components/app-header";

export const metadata: Metadata = {
  title: "Legal · MUP — Java Residence",
  description: "Modul Legal: repository dokumen, timeline, dan persetujuan akses.",
};

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="id">
      <head>
        {/* Tabler icons webfont — same icon set as the reference mockups. */}
        <link
          rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.47.0/tabler-icons.min.css"
        />
      </head>
      <body>
        <AppHeader />
        <main className="mx-auto w-full max-w-[1320px] px-5 pb-16 pt-7 sm:px-8 lg:px-10">{children}</main>
      </body>
    </html>
  );
}
