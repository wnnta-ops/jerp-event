<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Services;

use App\Domain\BusinessSupport\Models\BsMukContract;
use App\Domain\BusinessSupport\Models\BsMukContractCogs;
use App\Domain\BusinessSupport\Models\BsMukLokasi;
use App\Domain\BusinessSupport\Models\BsMukUnitPasangan;
use App\Domain\Master\Unit\Models\Unit;
use Illuminate\Support\Collection;

/**
 * Seluruh SPK yang menyentuh SATU unit rumah, beserta nilai persediaan dalam prosesnya.
 *
 * SATU RUMAH DISENTUH BEBERAPA SPK — housing, lalu kanopi, lalu site instruction. Itu sebabnya
 * layar ini berporos pada rumahnya, bukan pada SPK-nya: yang dipindahkan ke Barang Siap Dijual
 * adalah RUMAHNYA, dan keputusan itu menuntut seluruh SPK yang membentuknya terlihat sekaligus.
 *
 * ── ANGKANYA DARI MUK PER LOKASI, BUKAN DIBAGI SENDIRI ──
 *
 * MUK memberi nilai kontrak, persen opname, dan nilai tertagih PER LOKASI (bs_muk_lokasi).
 * Ketiganya dipakai apa adanya:
 *
 *   Alokasi SPK (DPP)              = nilai_kontrak lokasi itu pada SPK itu
 *   % Progress                     = opname_persen lokasi itu
 *   Nominal Persediaan Dalam Proses = ditagih_nilai lokasi itu
 *
 * Membaginya sendiri menurut luas (lihat AlokasiSpkUnit) dipakai di tempat lain, untuk hal
 * lain: di sana MUK tidak memberi rinciannya. Di sini mereka memberi, dan angka mereka yang
 * berlaku — kalau kita membagi ulang, angka di layar ini akan berbeda dari dokumen COP yang
 * dipegang orang yang sama.
 *
 * ── RUMAH YANG BELUM DIPASANGKAN TIDAK PUNYA APA-APA DI SINI ──
 *
 * Semuanya bergantung pada pasangan lokasi MUK ↔ unit kita (bs_muk_unit_pasangan). Unit yang
 * belum dipasangkan memulangkan daftar kosong, dan layar mengatakan alasannya — bukan nol
 * yang terbaca seperti "rumah ini memang belum dikerjakan".
 */
class RincianUnitSpk
{
    public function __construct(private readonly BarisSpkMuk $baris) {}

    /**
     * @return array{
     *     unit: ?Unit, lokasi_kode: ?string, baris: array<int, array<string, mixed>>,
     *     total_alokasi: float, total_wip: float
     * }
     */
    public function untukUnit(int $unitId): array
    {
        $unit = Unit::with('cluster:id,code,name')->find($unitId);
        $pasangan = BsMukUnitPasangan::where('unit_id', $unitId)->first();

        $kosong = [
            'unit' => $unit,
            'lokasi_kode' => $pasangan?->lokasi_kode,
            'baris' => [],
            'total_alokasi' => 0.0,
            'total_wip' => 0.0,
        ];

        if ($pasangan === null) {
            return $kosong;
        }

        $lokasi = BsMukLokasi::where('lokasi_kode', $pasangan->lokasi_kode)
            ->orderBy('muk_id')->get();

        if ($lokasi->isEmpty()) {
            return $kosong;
        }

        $mukId = $lokasi->pluck('muk_id')->unique();
        $kontrak = BsMukContract::whereIn('muk_id', $mukId)->get()->keyBy('muk_id');
        $cogs = BsMukContractCogs::whereIn('muk_id', $mukId)->get(['muk_id', 'component', 'kategori'])->keyBy('muk_id');
        $petaProyek = $this->baris->petaProyek();

        $baris = [];
        foreach ($lokasi as $l) {
            $k = $kontrak->get((int) $l->muk_id);
            // Lokasi yang kontraknya belum tersalin dilewati: tanpa kontraknya, No. SPK,
            // Lokasi, dan Tahap semuanya kosong, dan barisnya hanya angka tanpa identitas.
            if ($k === null) {
                continue;
            }

            $kolom = $this->baris->kolom($k, $petaProyek->get((string) $k->proyek_kode));
            $c = $cogs->get((int) $l->muk_id);

            $baris[] = [
                'muk_id' => (int) $l->muk_id,
                'kontrak_id' => $k->id,
                'lokasi' => $kolom['lokasi'],
                'tahap' => $kolom['tahap'],
                'no_spk' => $kolom['no_spk'] ?? $k->no_kontrak,
                'kategori_cogs' => $c?->kategori,
                'komponen_cogs' => $c?->component,
                'alokasi_dpp' => (float) $l->nilai_kontrak,
                'progress_persen' => (float) $l->opname_persen,
                'wip' => (float) $l->ditagih_nilai,
            ];
        }

        return [
            'unit' => $unit,
            'lokasi_kode' => $pasangan->lokasi_kode,
            'baris' => $baris,
            'total_alokasi' => array_sum(array_column($baris, 'alokasi_dpp')),
            // INILAH yang dipindahkan ke Barang Siap Dijual — jumlah yang sudah ditagihkan
            // untuk rumah ini dari seluruh SPK-nya, bukan nilai kontraknya. Nilai kontrak
            // adalah rencana; yang sudah menjadi biaya hanyalah yang sudah ditagihkan.
            'total_wip' => array_sum(array_column($baris, 'wip')),
        ];
    }

    /**
     * Unit rumah milik satu kontrak MUK — untuk pemilih di layar rincian.
     *
     * @return Collection<int, array{id: int, unit_code: ?string, lokasi_kode: string}>
     */
    public function unitKontrak(BsMukContract $kontrak): Collection
    {
        return BsMukLokasi::query()
            ->where('bs_muk_lokasi.muk_id', (int) $kontrak->muk_id)
            ->join('bs_muk_unit_pasangan', 'bs_muk_unit_pasangan.lokasi_kode', '=', 'bs_muk_lokasi.lokasi_kode')
            ->join('units', 'units.id', '=', 'bs_muk_unit_pasangan.unit_id')
            ->orderBy('units.unit_code')
            ->get(['units.id', 'units.unit_code', 'bs_muk_lokasi.lokasi_kode'])
            ->unique('id')
            ->map(fn ($u) => [
                'id' => (int) $u->id,
                'unit_code' => $u->unit_code,
                'lokasi_kode' => (string) $u->lokasi_kode,
            ])
            ->values();
    }
}
