import { prisma } from "@/lib/prisma";
import { angkaRupiah, dorongAjb, lepasAjb } from "@/lib/jerp-bridge";
import { isSprLinked, type DocType } from "@/lib/enums";

/**
 * Menyalurkan dokumen AJB ke **Detail AJB** (Booked Revenue) lewat Java Core.
 *
 * Dipanggil sesudah dokumen tersimpan, BUKAN sebelum — dan kegagalannya tidak
 * pernah membatalkan penyimpanan. Alasannya: arsip legal adalah kewajiban modul
 * ini sendiri; kalau Java Core sedang mati, akta tetap harus bisa diarsipkan.
 * Yang terjadi hanyalah tautannya tertunda, dan itu tercatat di `sprSyncError`
 * supaya kelihatan di drawer dokumen — bukan hilang diam-diam.
 */

type DokumenAjb = {
  id: string;
  docType: string;
  docNumber: string | null;
  issueDate: Date | null;
  sprNumber: string | null;
  notaryName: string | null;
  ajbValue: string | null;
};

export type HasilSinkron = { ok: boolean; pesan: string | null };

/** yyyy-mm-dd dalam UTC — tanggal disimpan sebagai tengah malam UTC di sini. */
function tanggalIso(d: Date | null): string | null {
  return d ? d.toISOString().slice(0, 10) : null;
}

/** Catat hasil dorongan ke dokumen supaya bisa dilihat & diulang petugas. */
async function catat(docId: string, galat: string | null): Promise<void> {
  await prisma.document.update({
    where: { id: docId },
    data: { sprSyncedAt: galat ? null : new Date(), sprSyncError: galat },
  });
}

/**
 * Sinkronkan satu dokumen. Aman dipanggil untuk tipe apa pun: dokumen yang bukan
 * AJB (atau yang tautannya dilepas) justru MELEPAS baris di Back Office — kalau
 * tidak, mengubah tipe dokumen dari AJB ke lainnya akan meninggalkan baris yatim
 * yang isinya tak lagi punya dokumen di modul ini.
 */
export async function sinkronAjb(doc: DokumenAjb): Promise<HasilSinkron> {
  const tertaut = isSprLinked(doc.docType as DocType) && !!doc.sprNumber;

  if (!tertaut) {
    const r = await lepasAjb(doc.id);
    // Gagal melepas tidak dicatat sebagai galat dokumen: dokumennya memang sudah
    // tidak mengklaim tautan apa pun, jadi tidak ada yang perlu ditindaklanjuti
    // petugas Legal di layar ini.
    await catat(doc.id, null);
    return { ok: r.ok, pesan: r.ok ? null : r.pesan };
  }

  const tanggal = tanggalIso(doc.issueDate);
  if (!doc.docNumber || !tanggal) {
    const pesan = "Nomor akta & tanggal terbit harus terisi sebelum bisa ditautkan ke Detail AJB.";
    await catat(doc.id, pesan);
    return { ok: false, pesan };
  }

  const r = await dorongAjb({
    legal_doc_id: doc.id,
    spr: doc.sprNumber!,
    ajb_number: doc.docNumber,
    signed_at: tanggal,
    notary_name: doc.notaryName,
    amount: angkaRupiah(doc.ajbValue),
  });

  await catat(doc.id, r.ok ? null : r.pesan);
  return { ok: r.ok, pesan: r.ok ? null : r.pesan };
}

/** Dokumen dihapus — baris di Back Office ikut dilepas. */
export async function lepasSinkronAjb(docId: string): Promise<void> {
  await lepasAjb(docId);
}
