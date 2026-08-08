import { z } from "zod";
import {
  ACCESS_LEVELS,
  DOC_STATUSES,
  DOC_TYPES,
  ENTITY_TYPES,
  EXPIRY_REQUIRED_TYPES,
  ORIGINAL_LOCATIONS,
  TRANSACTION_TYPES,
  categoryOfDocType,
  type DocType,
} from "./enums";

/**
 * Upload validation — "the point of the module" (spec §4).
 *
 * Always required: doc_type, title, status, pic, original_location,
 * access_level, and >= 1 file (file count is checked in the server action,
 * since files arrive as FormData, not as part of this object).
 *
 * Adaptive requirements, by doc_type:
 *   - Transaction (PPJB/AJB/Sertifikat/KPR) -> cluster, blok, unit_no
 *   - Expiry-bound (Izin/KPR)               -> expiry_date
 *   - Template                              -> version
 *
 * This same schema runs on the client (instant inline errors) and again in the
 * server action (the authority). One definition, no drift.
 */

const nonEmpty = (msg = "Wajib diisi") => z.string().trim().min(1, msg);
const optionalStr = z
  .string()
  .trim()
  .optional()
  .transform((v) => (v === "" ? undefined : v));

// Accepts "" / undefined / "yyyy-mm-dd". Validates real calendar dates.
const optionalDate = z
  .string()
  .optional()
  .transform((v) => (v && v.trim() !== "" ? v.trim() : undefined))
  .refine((v) => v === undefined || !Number.isNaN(Date.parse(v)), { message: "Tanggal tidak valid" });

export const documentInputSchema = z
  .object({
    docType: z.enum(DOC_TYPES, { errorMap: () => ({ message: "Pilih jenis dokumen" }) }),
    title: nonEmpty("Judul dokumen wajib diisi"),
    docNumber: optionalStr,

    cluster: optionalStr,
    blok: optionalStr,
    unitNo: optionalStr,
    buyerName: optionalStr,
    entityName: optionalStr,

    status: z.enum(DOC_STATUSES, { errorMap: () => ({ message: "Pilih status" }) }),
    issueDate: optionalDate,
    expiryDate: optionalDate,

    picId: nonEmpty("Pilih penanggung jawab (PIC)"),
    originalLocation: z.enum(ORIGINAL_LOCATIONS, { errorMap: () => ({ message: "Pilih lokasi original" }) }),
    originalLocationDetail: optionalStr,

    version: optionalStr,
    isCurrentApproved: z.boolean().optional().default(false),

    // Category-specific extras.
    objectLocation: optionalStr,
    landArea: optionalStr,
    notes: optionalStr,
    description: optionalStr,
    boardStructure: optionalStr,
    authorizedCapital: optionalStr,
    issuedCapital: optionalStr,
    shareholders: optionalStr, // JSON string
    parties: optionalStr, // JSON string

    reminderDate: optionalDate,
    reminderNote: optionalStr,

    accessLevel: z.enum(ACCESS_LEVELS, { errorMap: () => ({ message: "Pilih tingkat akses" }) }),
  })
  .superRefine((data, ctx) => {
    const t = data.docType as DocType;

    if (TRANSACTION_TYPES.includes(t)) {
      if (!data.cluster) ctx.addIssue({ code: "custom", path: ["cluster"], message: "Cluster wajib diisi" });
      if (!data.blok) ctx.addIssue({ code: "custom", path: ["blok"], message: "Blok wajib diisi" });
      if (!data.unitNo) ctx.addIssue({ code: "custom", path: ["unitNo"], message: "Nomor unit wajib diisi" });
    }

    if (ENTITY_TYPES.includes(t) && !data.entityName) {
      ctx.addIssue({ code: "custom", path: ["entityName"], message: "Nama entitas / PT wajib diisi" });
    }

    if (EXPIRY_REQUIRED_TYPES.includes(t) && !data.expiryDate) {
      ctx.addIssue({ code: "custom", path: ["expiryDate"], message: "Tanggal kedaluwarsa / berlaku wajib diisi" });
    }

    // Perizinan: Tanggal Terbit wajib.
    if (categoryOfDocType(t) === "perizinan" && !data.issueDate) {
      ctx.addIssue({ code: "custom", path: ["issueDate"], message: "Tanggal terbit wajib diisi" });
    }
  });

export type DocumentInput = z.input<typeof documentInputSchema>;
export type DocumentParsed = z.output<typeof documentInputSchema>;

/** Flatten Zod issues into a { field: message } map for the form. */
export function fieldErrors(error: z.ZodError): Record<string, string> {
  const out: Record<string, string> = {};
  for (const issue of error.issues) {
    const key = issue.path[0];
    if (typeof key === "string" && !out[key]) out[key] = issue.message;
  }
  return out;
}
