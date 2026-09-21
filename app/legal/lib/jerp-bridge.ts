/**
 * Jembatan modul Legal → J-ERP (Java Core / FO) → ipro_bo (Back Office).
 *
 * Dipakai dokumen bertipe **AJB**: aktanya diarsipkan di sini, lalu ditautkan ke
 * sebuah **No. SPR (Final)** milik Marketing & Sales. FO yang memiliki data SPR
 * (fo_kontrak) sekaligus jalur tulis ke ipro_bo, jadi kedua langkah itu dikerjakan
 * di sana lewat endpoint `/api/legal-bridge/*`. Modul ini tidak pernah menyentuh
 * database mana pun selain Postgres miliknya sendiri.
 *
 * KEAMANAN — tiap permintaan ditandatangani HMAC-SHA256 atas pesan kanonik:
 *
 *     <timestamp unix> \n <path> \n <sha256 hex dari body>
 *
 * Kuncinya DITURUNKAN dari JERP_SSO_SECRET (yang sudah dibagikan container ini
 * lewat compose) dengan pemisah domain, jadi tidak ada variabel .env baru yang
 * wajib diisi — dan token SSO yang bocor tetap tak bisa dipakai memanggil API ini.
 * Turunan yang sama dihitung sisi PHP di config/ipro.php.
 *
 * SEMUA ENDPOINT POST + body JSON. Itu disengaja: kalau query string ikut
 * ditandatangani, perbedaan halus persen-encoding antara Node, nginx, dan PHP
 * akan menolak tanda tangan yang sebetulnya sah — kegagalan yang sangat sulit
 * dilacak karena hanya muncul untuk sebagian kata kunci pencarian.
 */

import { createHmac, createHash } from "crypto";

/** Harus sama persis dengan config/ipro.php → legal_bridge_secret. */
const PEMISAH_DOMAIN = "legal-bridge-v1";

/** Batas tunggu: form penaut SPR harus tetap terasa hidup walau FO lambat. */
const TIMEOUT_MS = 8000;

export type SprRingkas = {
  spr: string;
  customer: string | null;
  unit: string | null;
  cluster: string | null;
  blok: string | null;
  no_unit: string | null;
  tanggal: string | null;
  total: number | null;
  status: string | null;
  cara_bayar: string | null;
};

export type HasilJembatan<T> = { ok: true; data: T } | { ok: false; pesan: string; kode?: string };

/**
 * URL internal J-ERP. Di compose, container ini satu jaringan dengan `nginx`
 * (service `app` adalah PHP-FPM port 9000 — bukan HTTP, jadi tidak bisa dituju
 * langsung). Bisa ditimpa lewat JERP_BASE_URL bila susunannya berbeda.
 */
function basisUrl(): string {
  return (process.env.JERP_BASE_URL || "http://nginx").replace(/\/+$/, "");
}

/** Kunci tanda tangan, atau null bila belum dikonfigurasi (fail-closed). */
function kunci(): string | null {
  const langsung = process.env.LEGAL_BRIDGE_SECRET?.trim();
  if (langsung) return langsung;

  const portal = process.env.JERP_SSO_SECRET?.trim();
  if (!portal) return null; // kunci turunan dari string kosong bisa dihitung siapa pun

  return createHmac("sha256", portal).update(PEMISAH_DOMAIN).digest("hex");
}

/** True bila jembatan bisa dipakai — dipakai UI untuk menjelaskan kenapa tidak. */
export function jembatanSiap(): boolean {
  return kunci() !== null;
}

async function panggil<T>(path: string, muatan: unknown): Promise<HasilJembatan<T>> {
  const rahasia = kunci();
  if (!rahasia) {
    return { ok: false, kode: "BELUM_DIKONFIGURASI", pesan: "Sambungan ke Java Core belum dikonfigurasi (JERP_SSO_SECRET kosong)." };
  }

  const body = JSON.stringify(muatan ?? {});
  const ts = String(Math.floor(Date.now() / 1000));
  const pesan = `${ts}\n${path}\n${createHash("sha256").update(body).digest("hex")}`;
  const sig = createHmac("sha256", rahasia).update(pesan).digest("hex");

  const batal = new AbortController();
  const jam = setTimeout(() => batal.abort(), TIMEOUT_MS);
  try {
    const res = await fetch(`${basisUrl()}${path}`, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Accept: "application/json",
        "X-Legal-Timestamp": ts,
        "X-Legal-Signature": sig,
      },
      body,
      signal: batal.signal,
      cache: "no-store",
    });

    // Respons non-JSON berarti permintaan tak sampai ke Laravel (nginx 502, halaman
    // error HTML, …). Menelannya sebagai "gagal" tanpa keterangan membuat petugas
    // mengira datanya yang salah, padahal sambungannya yang putus.
    const teks = await res.text();
    let isi: Record<string, unknown>;
    try {
      isi = JSON.parse(teks) as Record<string, unknown>;
    } catch {
      return { ok: false, kode: "BALASAN_TIDAK_DIKENAL", pesan: `Java Core membalas ${res.status} (bukan JSON).` };
    }

    if (!res.ok || isi.ok !== true) {
      return {
        ok: false,
        kode: typeof isi.kode === "string" ? isi.kode : `HTTP_${res.status}`,
        pesan: typeof isi.pesan === "string" ? isi.pesan : `Java Core menolak permintaan (${res.status}).`,
      };
    }
    return { ok: true, data: isi as T };
  } catch (e) {
    const timeout = e instanceof Error && e.name === "AbortError";
    return {
      ok: false,
      kode: timeout ? "TIMEOUT" : "TAK_TERJANGKAU",
      pesan: timeout ? "Java Core tidak membalas tepat waktu." : "Java Core tidak bisa dihubungi.",
    };
  }
}

/** Cari SPR (Final) untuk penaut di formulir dokumen AJB. */
export async function cariSpr(q: string, limit = 20): Promise<HasilJembatan<SprRingkas[]>> {
  const r = await panggil<{ data?: SprRingkas[] }>("/api/legal-bridge/spr", { q, limit });

  return r.ok ? { ok: true, data: Array.isArray(r.data.data) ? r.data.data : [] } : r;
}

export type DorongAjb = {
  legal_doc_id: string;
  spr: string;
  ajb_number: string;
  /** yyyy-mm-dd */
  signed_at: string;
  notary_name?: string | null;
  amount?: number | null;
};

/** Dorong akta AJB ke Detail AJB (Booked Revenue) lewat FO. */
export async function dorongAjb(d: DorongAjb): Promise<HasilJembatan<{ pesan: string; ditimpa?: boolean }>> {
  return panggil<{ pesan: string; ditimpa?: boolean }>("/api/legal-bridge/ajb", d);
}

/** Lepas tautan — dokumen dihapus, tipenya bukan AJB lagi, atau SPR-nya dikosongkan. */
export async function lepasAjb(legalDocId: string): Promise<HasilJembatan<{ pesan: string; dihapus?: number }>> {
  return panggil<{ pesan: string; dihapus?: number }>("/api/legal-bridge/ajb/lepas", { legal_doc_id: legalDocId });
}

/**
 * Angka rupiah yang diketik bebas ("Rp 501.000.000", "501000000", "501.000.000,00")
 * → number untuk dikirim ke FO. Mengembalikan null bila tidak ada angka sama sekali.
 *
 * Pemisah ribuan di Indonesia adalah titik dan desimalnya koma — kebalikan dari
 * parseFloat bawaan. Tanpa penyesuaian ini "501.000.000" terbaca 501 dan nominal
 * akta di Back Office menyusut sejuta kali lipat tanpa satu pun galat muncul.
 */
export function angkaRupiah(teks: string | null | undefined): number | null {
  if (!teks) return null;
  const bersih = String(teks).replace(/[^\d,.-]/g, "");
  if (!/\d/.test(bersih)) return null;

  const adaKoma = bersih.includes(",");
  const normal = adaKoma
    ? bersih.replace(/\./g, "").replace(",", ".") // 1.234.567,89 → 1234567.89
    : bersih.replace(/\.(?=\d{3}(\D|$))/g, ""); // 1.234.567 → 1234567 (titik ribuan saja)

  const n = Number(normal);
  return Number.isFinite(n) ? n : null;
}
