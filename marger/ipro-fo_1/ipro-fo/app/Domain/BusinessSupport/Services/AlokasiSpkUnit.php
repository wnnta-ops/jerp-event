<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Services;

use App\Domain\BusinessSupport\Models\BsMukContract;
use App\Domain\BusinessSupport\Models\BsMukContractCogs;
use App\Domain\BusinessSupport\Models\BsMukLokasi;
use App\Domain\BusinessSupport\Models\BsProjectMuk;
use App\Domain\Master\Unit\Models\Unit;

/**
 * Alokasi nilai satu SPK ke unit-unit rumahnya (Lampiran 1).
 *
 * Aturannya dari pemilik proses:
 *
 *   Housing       : alokasi = (LUAS BANGUNAN unit / total luas bangunan) x nilai SPK
 *   Infrastruktur : alokasi = (LUAS TANAH unit    / total luas tanah)    x nilai SPK
 *
 * ── UNITNYA DARI PASANGAN, BUKAN DARI TEBAKAN ──
 *
 * Yang ikut dihitung HANYA lokasi MUK yang sudah dipasangkan ke unit kita. Itu keputusan
 * pemilik proses, dan konsekuensinya perlu diingat: alokasi BERGESER setiap kali pasangan
 * baru dibuat, karena pembaginya ikut bertambah. Angka hari ini dan angka minggu depan bisa
 * berbeda untuk unit yang sama. Karena itu jumlah yang belum dipasangkan ikut dipulangkan —
 * supaya layar bisa mengatakannya, bukan menyembunyikannya.
 *
 * ── INFRASTRUKTUR UMUMNYA TIDAK PUNYA UNIT ──
 *
 * Diperiksa 1 Okt 2026: dari 95 kontrak, 10 memulangkan lokasi tanpa identitas sama sekali —
 * seluruhnya paket infra dan site instruction. Pekerjaan semacam itu memang bukan milik satu
 * rumah. Bila tidak ada pasangan, kelas ini memulangkan daftar kosong dan layar mengatakan
 * alasannya; rumus luas tanah tetap ada dan langsung berlaku bila MUK kelak mengirim
 * lokasinya.
 *
 * ── PEMBULATAN TIDAK BOLEH MENGUBAH TOTAL ──
 *
 * Pembagian proporsional selalu meninggalkan sisa rupiah. Sisanya DITEMPELKAN pada unit
 * dengan luas terbesar, sehingga jumlah seluruh alokasi PERSIS sama dengan nilai SPK. Tanpa
 * itu, menjumlahkan kolom di layar menghasilkan angka yang berbeda beberapa rupiah dari SPK-
 * nya — dan selisih yang tak bisa dijelaskan membuat orang meragukan seluruh tabelnya.
 */
class AlokasiSpkUnit
{
    /**
     * @return array{
     *     dasar: string, komponen: ?string, nilai_dibagi: float,
     *     total_dasar: float, baris: array<int, array<string, mixed>>,
     *     lokasi_total: int, lokasi_belum_dipasangkan: int, lokasi: ?string, tahap: ?string
     * }
     */
    public function untuk(BsMukContract $kontrak): array
    {
        $komponen = BsMukContractCogs::where('muk_id', (int) $kontrak->muk_id)->value('component');

        // Infrastruktur memakai luas TANAH, selain itu luas BANGUNAN. Komponen yang belum
        // ditautkan diperlakukan sebagai bangunan: itu dugaan paling tidak merusak, dan
        // layar tetap menyebut komponennya supaya terlihat bila belum dipilih.
        $dasar = $komponen === 'infrastruktur' ? 'luas_tanah' : 'luas_bangunan';

        $proyek = BsProjectMuk::query()
            ->join('bs_projects', 'bs_projects.id', '=', 'bs_project_muk.project_id')
            ->where('bs_project_muk.muk_proyek_kode', (string) $kontrak->proyek_kode)
            ->first(['bs_projects.cluster', 'bs_projects.tahap']);

        $lokasi = BsMukLokasi::query()
            ->where('bs_muk_lokasi.muk_id', (int) $kontrak->muk_id)
            ->leftJoin('bs_muk_unit_pasangan', 'bs_muk_unit_pasangan.lokasi_kode', '=', 'bs_muk_lokasi.lokasi_kode')
            ->orderBy('bs_muk_lokasi.nama')
            ->get(['bs_muk_lokasi.lokasi_kode', 'bs_muk_lokasi.nama', 'bs_muk_unit_pasangan.unit_id']);

        $belum = $lokasi->whereNull('unit_id')->count();
        $unitId = $lokasi->pluck('unit_id')->filter()->unique()->values();

        $kosong = [
            'dasar' => $dasar,
            'komponen' => $komponen,
            'nilai_dibagi' => (float) $kontrak->nilai_kontrak,
            'total_dasar' => 0.0,
            'baris' => [],
            'lokasi_total' => $lokasi->count(),
            'lokasi_belum_dipasangkan' => $belum,
            'lokasi' => $proyek?->cluster,
            'tahap' => $proyek?->tahap,
        ];

        if ($unitId->isEmpty()) {
            return $kosong;
        }

        $unit = Unit::with(['unitType:id,lt,lb', 'cluster:id,code,name'])
            ->whereIn('id', $unitId)->orderBy('unit_code')->get();

        // Luas diambil dari UNITNYA dulu, master tipe sebagai cadangan: dua unit bertipe sama
        // bisa berbeda luas tanahnya (unit pojok, tusuk sate), dan angka tipe menyeragamkan
        // sesuatu yang memang tidak seragam.
        $luas = [];
        foreach ($unit as $u) {
            $attr = is_array($u->getAttribute('attributes')) ? $u->getAttribute('attributes') : [];
            $luas[$u->id] = (float) ($dasar === 'luas_tanah'
                ? ($attr['luas_tanah'] ?? $u->unitType?->lt ?? 0)
                : ($attr['luas_bangunan'] ?? $u->unitType?->lb ?? 0));
        }

        $total = array_sum($luas);
        $nilai = (float) $kontrak->nilai_kontrak;

        if ($total <= 0) {
            // Tanpa luas tidak ada dasar pembagian. Dipulangkan kosong dengan total 0 supaya
            // layar mengatakan "luas belum terisi" alih-alih membagi dengan nol atau — lebih
            // buruk — membagi rata dan tampak masuk akal.
            return $kosong;
        }

        $baris = [];
        foreach ($unit as $u) {
            $l = $luas[$u->id];
            $baris[] = [
                'unit_id' => $u->id,
                'unit_rumah' => $u->unit_code,
                'lokasi' => $proyek?->cluster ?: ($u->cluster?->name ?? null),
                'tahap' => $proyek?->tahap,
                'luas' => $l,
                'alokasi_dpp' => (float) round($nilai * $l / $total),
                // PPN & PPh 4(2) BELUM DIKIRIM MUK — tidak ada di endpoint kontrak, hanya per
                // tagihan (dan itu nilai yang sudah ditagih, bukan nilai SPK). null, bukan 0:
                // nol adalah angka, dan orang akan memakainya.
                'alokasi_ppn' => null,
                'alokasi_pph42' => null,
            ];
        }

        // Sisa pembulatan ditempelkan pada unit berluas terbesar — pengaruhnya paling kecil
        // secara relatif di situ, dan totalnya menjadi persis.
        $selisih = round($nilai - array_sum(array_column($baris, 'alokasi_dpp')));
        if (abs($selisih) >= 0.5 && $baris !== []) {
            $i = array_search(max(array_column($baris, 'luas')), array_column($baris, 'luas'), true);
            $baris[$i]['alokasi_dpp'] += $selisih;
        }

        return [
            'dasar' => $dasar,
            'komponen' => $komponen,
            'nilai_dibagi' => $nilai,
            'total_dasar' => $total,
            'baris' => $baris,
            'lokasi_total' => $lokasi->count(),
            'lokasi_belum_dipasangkan' => $belum,
            'lokasi' => $proyek?->cluster,
            'tahap' => $proyek?->tahap,
        ];
    }
}
