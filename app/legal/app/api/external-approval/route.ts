import { NextRequest } from "next/server";
import { prisma } from "@/lib/prisma";

export const dynamic = "force-dynamic";
export const runtime = "nodejs";

/**
 * Inbound callback from the separate approval app (GM → Direktur → Owner) for a
 * physical-original checkout that is "pending_external". Open-ended seam.
 *
 * POST { code | requestId, decision: "approved" | "rejected", note?, by? }
 * Header: x-approval-secret (required iff EXTERNAL_APPROVAL_SECRET is set).
 */
export async function POST(req: NextRequest) {
  const secret = process.env.EXTERNAL_APPROVAL_SECRET;
  if (secret && req.headers.get("x-approval-secret") !== secret) {
    return new Response("Forbidden", { status: 403 });
  }

  let body: { code?: string; requestId?: string; decision?: string; note?: string; by?: string };
  try {
    body = await req.json();
  } catch {
    return new Response("Bad request", { status: 400 });
  }

  const decision = body.decision;
  if (decision !== "approved" && decision !== "rejected") {
    return Response.json({ ok: false, error: "decision harus 'approved' atau 'rejected'" }, { status: 400 });
  }
  const where = body.requestId ? { id: body.requestId } : body.code ? { code: body.code } : null;
  if (!where) return Response.json({ ok: false, error: "requestId atau code wajib" }, { status: 400 });

  const reqRow = await prisma.accessRequest.findUnique({ where });
  if (!reqRow) return Response.json({ ok: false, error: "Permintaan tidak ditemukan" }, { status: 404 });
  if (reqRow.status !== "pending_external") {
    return Response.json({ ok: false, error: `Permintaan tidak menunggu approval eksternal (status: ${reqRow.status})` }, { status: 409 });
  }

  const newStatus = decision === "approved" ? "approved" : "rejected";
  await prisma.$transaction(async (tx) => {
    await tx.accessRequest.update({ where: { id: reqRow.id }, data: { status: newStatus } });
    await tx.auditEntry.create({
      data: {
        requestId: reqRow.id,
        action: decision === "approved" ? "external_approved" : "external_rejected",
        detail: `Approval eksternal (GM → Direktur → Owner)${body.by ? ` oleh ${body.by}` : ""}${body.note ? `: ${body.note}` : ""}`,
      },
    });
  });

  return Response.json({ ok: true, status: newStatus });
}
