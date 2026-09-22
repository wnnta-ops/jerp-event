import { NextRequest, NextResponse } from "next/server";
import { getActingUser } from "@/lib/current-user";
import { cariSpr } from "@/lib/jerp-bridge";

export const dynamic = "force-dynamic";

/**
 * Autocomplete **No. SPR (Final)** untuk formulir dokumen AJB.
 *
 * Ini perantara, bukan sekadar pembungkus: kunci tanda tangan jembatan ada di
 * server dan TIDAK BOLEH sampai ke peramban, jadi formulir memanggil rute ini
 * dan rute ini yang memanggil Java Core.
 *
 * Dijaga sesi. Tanpa itu, daftar pembeli + unit + nilai kontrak seluruh proyek
 * bisa dibaca siapa saja yang tahu alamatnya — data yang di modul ini sendiri
 * ditempatkan di balik login.
 */
export async function GET(req: NextRequest) {
  const acting = await getActingUser();
  if (!acting) return NextResponse.json({ ok: false, pesan: "Tidak ada pengguna aktif." }, { status: 401 });

  const q = (req.nextUrl.searchParams.get("q") ?? "").slice(0, 100);
  const hasil = await cariSpr(q, 20);

  if (!hasil.ok) {
    // 200 dengan ok:false — formulir menampilkan pesannya sebagai catatan di
    // bawah kolom, bukan melempar galat jaringan yang tidak bisa dibaca petugas.
    return NextResponse.json({ ok: false, pesan: hasil.pesan, kode: hasil.kode });
  }
  return NextResponse.json({ ok: true, data: hasil.data });
}
