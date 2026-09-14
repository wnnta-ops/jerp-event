import { prisma } from "./prisma";

/**
 * Resolve actor ids (User ids, or the literal "seed") to display names for
 * history timelines.
 */
export async function resolveActors(ids: string[]): Promise<Map<string, string>> {
  const map = new Map<string, string>();
  const real = [...new Set(ids)].filter((id) => id && id !== "seed");
  if (real.length) {
    const users = await prisma.user.findMany({
      where: { id: { in: real } },
      select: { id: true, name: true },
    });
    for (const u of users) map.set(u.id, u.name);
  }
  return map;
}

export function actorName(id: string, map: Map<string, string>): string {
  if (id === "seed") return "Import (data awal)";
  return map.get(id) ?? "Sistem";
}
