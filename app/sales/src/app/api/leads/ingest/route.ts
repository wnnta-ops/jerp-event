import { z } from "zod";
import { prisma } from "@/lib/prisma";
import { ok, handleError, ApiError } from "@/lib/api";
import { parseDayKey } from "@/lib/wib";

// Satu baris lead dari MarComm (mis. baris Google Sheet).
const leadIn = z.object({
  externalId: z.string().optional().nullable(), // id baris sheet — kunci idempoten
  nama: z.string().min(1),
  phone: z.string().optional().nullable(),
  source: z.string().optional().nullable(), // Meta / TikTok / dsb
  campaign: z.string().optional().nullable(),
  tanggal: z.string().regex(/^\d{4}-\d{2}-\d{2}$/).optional().nullable(),
  koor: z.string().optional().nullable(), // nama Sales Koor pemilik
});

const schema = z.union([leadIn, z.object({ leads: z.array(leadIn) })]);

// Webhook masuk dari MarComm. Diamankan header rahasia (bukan sesi user):
//   x-leads-secret: <LEADS_INGEST_SECRET>
// Idempoten: baris dengan externalId yang sama akan di-update, bukan duplikat.
// Koor dicocokkan berdasar NAMA (case-insensitive); bila tak dikenal → null
// (belum ter-assign, Management bisa menautkan nanti).
export async function POST(req: Request) {
  try {
    const secret = process.env.LEADS_INGEST_SECRET;
    if (!secret) {
      throw new ApiError(501, "Integrasi Leads belum dikonfigurasi (LEADS_INGEST_SECRET kosong).");
    }
    if (req.headers.get("x-leads-secret") !== secret) {
      throw new ApiError(401, "Secret tidak valid.");
    }

    const parsed = schema.parse(await req.json());
    const items = "leads" in parsed ? parsed.leads : [parsed];

    // cache resolusi koor per nama supaya tidak query berulang
    const koorCache = new Map<string, string | null>();
    const resolveKoor = async (name?: string | null): Promise<string | null> => {
      const key = name?.trim().toLowerCase();
      if (!key) return null;
      if (koorCache.has(key)) return koorCache.get(key)!;
      const c = await prisma.coordinator.findFirst({
        where: { name: { equals: name!.trim(), mode: "insensitive" } },
        select: { id: true },
      });
      koorCache.set(key, c?.id ?? null);
      return c?.id ?? null;
    };

    let created = 0;
    let updated = 0;
    for (const it of items) {
      const coordinatorId = await resolveKoor(it.koor);
      const data = {
        nama: it.nama,
        phone: it.phone ?? null,
        source: it.source ?? null,
        campaign: it.campaign ?? null,
        ...(it.tanggal ? { tanggal: parseDayKey(it.tanggal) } : {}),
        coordinatorId,
      };
      if (it.externalId) {
        const res = await prisma.lead.upsert({
          where: { externalId: it.externalId },
          // jangan timpa kondisi yang sudah diisi Sales Koor (minat/status/note/followUp)
          update: data,
          create: { externalId: it.externalId, ...data },
        });
        // upsert tak memberi tahu create vs update — hitung kasar via createdAt
        if (res.createdAt.getTime() === res.updatedAt.getTime()) created++;
        else updated++;
      } else {
        await prisma.lead.create({ data });
        created++;
      }
    }

    return ok({ ok: true, received: items.length, created, updated });
  } catch (e) {
    return handleError(e);
  }
}
