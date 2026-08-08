import { NextRequest } from "next/server";
import { readFile, stat } from "fs/promises";
import path from "path";

export const dynamic = "force-dynamic";

// Serve the in-place imported documents — STRICTLY from the two allowed roots
// (no path traversal, nothing else on disk is reachable).
const ROOT_PARENT = path.resolve(process.cwd(), "..");
const ALLOWED_ROOTS = [
  path.join(ROOT_PARENT, "DASHBOARD DATA USED"), // new authoritative source
  path.join(ROOT_PARENT, "DEPARTEMEN LEGAL"),
  path.join(ROOT_PARENT, "LEGALITAS"),
];

const MIME: Record<string, string> = {
  ".pdf": "application/pdf",
  ".jpg": "image/jpeg",
  ".jpeg": "image/jpeg",
  ".png": "image/png",
  ".xlsx": "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
};

export async function GET(req: NextRequest) {
  const rel = req.nextUrl.searchParams.get("p");
  if (!rel) return new Response("Missing ?p", { status: 400 });

  const abs = path.resolve(ROOT_PARENT, rel);
  const inAllowed = ALLOWED_ROOTS.some((root) => abs === root || abs.startsWith(root + path.sep));
  if (!inAllowed) return new Response("Forbidden", { status: 403 });

  try {
    const info = await stat(abs);
    if (!info.isFile()) return new Response("Not a file", { status: 404 });
    const buf = await readFile(abs);
    const ext = path.extname(abs).toLowerCase();
    return new Response(buf, {
      headers: {
        "Content-Type": MIME[ext] ?? "application/octet-stream",
        "Content-Disposition": `inline; filename="${encodeURIComponent(path.basename(abs))}"`,
        "Cache-Control": "private, max-age=60",
      },
    });
  } catch {
    return new Response("Not found", { status: 404 });
  }
}
