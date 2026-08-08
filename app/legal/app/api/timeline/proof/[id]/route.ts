import { NextRequest } from "next/server";
import { prisma } from "@/lib/prisma";
import { getActingUser } from "@/lib/current-user";

export const dynamic = "force-dynamic";
export const runtime = "nodejs";

/** Serve a checkpoint's proof file (stored in DB). Auth-gated. */
export async function GET(_req: NextRequest, { params }: { params: { id: string } }) {
  const acting = await getActingUser();
  if (!acting) return new Response("Unauthorized", { status: 401 });

  const cp = await prisma.timelineCheckpoint.findUnique({
    where: { id: params.id },
    select: { proofData: true, proofName: true, proofMime: true },
  });
  if (!cp?.proofData) return new Response("Tidak ada bukti", { status: 404 });

  return new Response(new Uint8Array(cp.proofData), {
    headers: {
      "Content-Type": cp.proofMime || "application/octet-stream",
      "Content-Disposition": `inline; filename="${encodeURIComponent(cp.proofName || "bukti")}"`,
      "Cache-Control": "no-store",
    },
  });
}
