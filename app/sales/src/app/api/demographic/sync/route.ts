import { z } from "zod";
import { prisma } from "@/lib/prisma";
import { ok, handleError, ApiError } from "@/lib/api";

// ---------------------------------------------------------------------------
// Tier-2 demographic ingestion pathway. An EXTERNAL system (CRM / survey tool)
// pushes customer demographic here; it is aggregated anonymously for the
// Pipeline Gagal AI. Machine-to-machine, so it is guarded by a bearer token
// (DEMOGRAPHIC_SYNC_TOKEN in .env) rather than a user session.
//
// The adapter accepts several common field aliases so it can be pointed at
// different source software with minimal changes. Map an external customer to
// our data via `dealId` (preferred) or `customerRef` (reconciled later).
//
//   POST /api/demographic/sync
//   Authorization: Bearer <DEMOGRAPHIC_SYNC_TOKEN>
//   { "records": [ { "dealId": "...", "umur": 32, "pekerjaan": "...",
//                    "domisili": "...", "penghasilan": "5-10jt" }, ... ] }
// ---------------------------------------------------------------------------

const record = z
  .object({
    dealId: z.string().min(1).optional(),
    customerRef: z.string().min(1).optional(),
    // accept common aliases from whatever software pushes the data
    umur: z.coerce.number().int().min(0).max(120).optional(),
    age: z.coerce.number().int().min(0).max(120).optional(),
    ageBand: z.string().optional(),
    pekerjaan: z.string().optional(),
    job: z.string().optional(),
    occupation: z.string().optional(),
    domisili: z.string().optional(),
    city: z.string().optional(),
    kota: z.string().optional(),
    penghasilan: z.string().optional(),
    income: z.string().optional(),
    source: z.string().optional(),
  })
  .refine((r) => r.dealId || r.customerRef, {
    message: "Tiap record wajib punya dealId atau customerRef.",
  });

const schema = z.object({ records: z.array(record).min(1).max(1000) });

function normalize(r: z.infer<typeof record>) {
  return {
    umur: r.umur ?? r.age ?? null,
    ageBand: r.ageBand ?? null,
    pekerjaan: r.pekerjaan ?? r.job ?? r.occupation ?? null,
    domisili: r.domisili ?? r.city ?? r.kota ?? null,
    penghasilan: r.penghasilan ?? r.income ?? null,
    source: r.source ?? null,
  };
}

export async function POST(req: Request) {
  try {
    const token = process.env.DEMOGRAPHIC_SYNC_TOKEN;
    if (!token) {
      throw new ApiError(
        501,
        "Pathway demografik belum dikonfigurasi — set DEMOGRAPHIC_SYNC_TOKEN di server.",
      );
    }
    const auth = req.headers.get("authorization") ?? "";
    if (auth !== `Bearer ${token}`) throw new ApiError(401, "Token tidak valid.");

    const { records } = schema.parse(await req.json());

    let synced = 0;
    const skipped: string[] = [];
    for (const raw of records) {
      const data = normalize(raw);
      if (raw.dealId) {
        const deal = await prisma.deal.findUnique({ where: { id: raw.dealId }, select: { id: true } });
        if (!deal) {
          skipped.push(raw.dealId);
          continue;
        }
        await prisma.customerDemographic.upsert({
          where: { dealId: raw.dealId },
          create: { dealId: raw.dealId, customerRef: raw.customerRef ?? null, ...data },
          update: { customerRef: raw.customerRef ?? undefined, ...data },
        });
      } else {
        await prisma.customerDemographic.upsert({
          where: { customerRef: raw.customerRef! },
          create: { customerRef: raw.customerRef!, ...data },
          update: data,
        });
      }
      synced++;
    }

    return ok({ ok: true, synced, skipped });
  } catch (e) {
    return handleError(e);
  }
}
