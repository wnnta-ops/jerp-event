import { NextRequest } from "next/server";
import { prisma } from "@/lib/prisma";
import { getActingUser } from "@/lib/current-user";

export const dynamic = "force-dynamic";
export const runtime = "nodejs";

// Hanya tipe yang aman ditampilkan INLINE di browser. Selain ini (mis. text/html,
// svg) dipaksa UNDUH sebagai octet-stream → cegah stored XSS dari MIME buatan
// penyerang yang tersimpan di kolom proofMime.
const INLINE_SAFE = new Set(["application/pdf", "image/png", "image/jpeg", "image/gif", "image/webp"]);

/** Serve a checkpoint's proof file (stored in DB). Auth-gated. */
export async function GET(_req: NextRequest, { params }: { params: { id: string } }) {
  const acting = await getActingUser();
  if (!acting) return new Response("Unauthorized", { status: 401 });

  const cp = await prisma.timelineCheckpoint.findUnique({
    where: { id: params.id },
    select: { proofData: true, proofName: true, proofMime: true },
  });
  if (!cp?.proofData) return new Response("Tidak ada bukti", { status: 404 });

  const safe = INLINE_SAFE.has(cp.proofMime ?? "");
  const mime = safe ? (cp.proofMime as string) : "application/octet-stream";
  const disposition = safe ? "inline" : "attachment";

  return new Response(new Uint8Array(cp.proofData), {
    headers: {
      "Content-Type": mime,
      "Content-Disposition": `${disposition}; filename="${encodeURIComponent(cp.proofName || "bukti")}"`,
      // nosniff: jangan biarkan browser menebak tipe (bypass allowlist).
      "X-Content-Type-Options": "nosniff",
      // sandbox: netralkan skrip andai tipe apa pun lolos tampil di browser.
      "Content-Security-Policy": "sandbox; default-src 'none'; img-src 'self' data:; object-src 'none'",
      "Cache-Control": "no-store",
    },
  });
}
