import { NextRequest } from "next/server";
import { readFile } from "fs/promises";
import path from "path";
import { prisma } from "@/lib/prisma";
import { getActingUser } from "@/lib/current-user";
import { REQUEST_DOCS_DIR as PRIVATE_ROOT } from "@/lib/storage";

export const dynamic = "force-dynamic";

const APPROVER_ROLES = new Set(["LEGAL_PIC", "HEAD_OF_LEGAL", "GENERAL_MANAGER", "DIRECTOR"]);
const MIME: Record<string, string> = {
  ".pdf": "application/pdf", ".jpg": "image/jpeg", ".jpeg": "image/jpeg", ".png": "image/png",
};

export async function GET(req: NextRequest) {
  const id = req.nextUrl.searchParams.get("id");
  if (!id) return new Response("Missing ?id", { status: 400 });

  const acting = await getActingUser();
  if (!acting) return new Response("Unauthorized", { status: 401 });

  const ar = await prisma.accessRequest.findUnique({
    where: { id },
    select: { requesterId: true, status: true, fulfilledFileUrl: true, fulfilledFileName: true },
  });
  if (!ar || !ar.fulfilledFileUrl) return new Response("Not found", { status: 404 });

  // Visibility: approvers (Legal + leadership) can always see the uploaded doc;
  // the requester can see it ONLY once the request is fully approved (released).
  // Rejected/pending → requester (and anyone else) cannot see it.
  const isApprover = APPROVER_ROLES.has(acting.role);
  const isRequesterAndReleased = acting.id === ar.requesterId && ar.status === "approved";
  if (!isApprover && !isRequesterAndReleased) return new Response("Forbidden", { status: 403 });

  // Resolve safely under PRIVATE_ROOT (no traversal).
  const abs = path.resolve(PRIVATE_ROOT, ar.fulfilledFileUrl);
  if (abs !== PRIVATE_ROOT && !abs.startsWith(PRIVATE_ROOT + path.sep)) return new Response("Forbidden", { status: 403 });

  try {
    const buf = await readFile(abs);
    const ext = path.extname(abs).toLowerCase();
    return new Response(buf, {
      headers: {
        "Content-Type": MIME[ext] ?? "application/octet-stream",
        "Content-Disposition": `inline; filename="${encodeURIComponent(ar.fulfilledFileName ?? path.basename(abs))}"`,
        "Cache-Control": "private, no-store",
      },
    });
  } catch {
    return new Response("Not found", { status: 404 });
  }
}
