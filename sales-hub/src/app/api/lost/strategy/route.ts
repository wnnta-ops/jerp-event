import { z } from "zod";
import { prisma } from "@/lib/prisma";
import { requireSession, requireRole, ok, handleError } from "@/lib/api";

const schema = z.object({ alasanKey: z.string().min(1), text: z.string() });

// Save the team's own appeal strategy (Slot A). Management only.
export async function POST(req: Request) {
  try {
    const session = await requireSession();
    requireRole(session.user.role, ["MANAGEMENT"]);
    const { alasanKey, text } = schema.parse(await req.json());
    const clean = text.trim() || null;

    await prisma.appealNote.upsert({
      where: { alasanKey },
      create: { alasanKey, teamStrategy: clean, updatedBy: session.user.id },
      update: { teamStrategy: clean, updatedBy: session.user.id },
    });

    return ok();
  } catch (e) {
    return handleError(e);
  }
}
