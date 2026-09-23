<?php

namespace App\Modules\FO\Collection\Services;

use App\Modules\FO\MarketingSales\Models\Kontrak;
use App\Modules\FO\MarketingSales\Models\KontrakTagihan;
use App\Modules\FO\MarketingSales\Models\Tahap;
use App\Settings\CollectionSettings;

/**
 * Biaya administrasi pembatalan kontrak, dan sisa yang dikembalikan ke pembeli.
 *
 *   biaya administrasi = persen × ANGSURAN yang sudah dibayar
 *   dikembalikan       = angsuran dibayar − biaya administrasi
 *
 * DASARNYA ANGSURAN, BUKAN HARGA UNIT. Yang dipotong adalah uang yang sudah benar-benar
 * masuk, bukan nilai kontraknya — pembeli yang baru menyetor sedikit tidak boleh berakhir
 * berutang biaya administrasi yang lebih besar daripada setorannya.
 *
 * "Angsuran" berarti tagihan bertipe ANG saja. Tiga tipe lain sengaja TIDAK ikut:
 *   ADM — biaya administrasi tagihan; memasukkannya berarti memotong yang sudah dipotong
 *   DP  — uang muka
 *   BF  — booking fee
 * Bila kelak DP/BF ikut diperhitungkan, ubah di SINI — satu tempat — supaya layar, cetakan,
 * dan approval tidak pernah menyebut angka yang berbeda.
 *
 * PERSENNYA per Cluster+Tahap, disimpan di fo_tahap.persen_batal_admin. fo_tahap memang
 * sudah satu baris per kombinasi itu (punya lokasi_id), jadi tidak perlu master baru.
 * Kolomnya nullable: null berarti "ikut persen bawaan", bukan nol persen.
 */
class BiayaBatalAngsuran
{
    public function __construct(private readonly CollectionSettings $setelan) {}

    /**
     * @return array{persen: float, angsuran: float, biaya_admin: float, dikembalikan: float, sumber: string}
     */
    public function hitung(Kontrak $kontrak): array
    {
        $angsuran = (float) KontrakTagihan::where('kontrak_id', $kontrak->id)
            ->where('tipe', 'ANG')->sum('pelunasan');

        [$persen, $sumber] = $this->persenUntuk($kontrak);
        $biaya = round($angsuran * $persen / 100, 2);

        return [
            'persen' => $persen,
            'angsuran' => $angsuran,
            'biaya_admin' => $biaya,
            // Dijaga tidak negatif: persen di atas 100 tidak boleh berubah menjadi TAGIHAN
            // kepada orang yang membatalkan.
            'dikembalikan' => max(0.0, round($angsuran - $biaya, 2)),
            'sumber' => $sumber,
        ];
    }

    /**
     * Persen yang berlaku, beserta ASALNYA — asalnya ikut dikembalikan supaya layar bisa
     * menyebut "Cluster JRK3 tahap 004" atau "bawaan". Tanpa itu, operator yang melihat
     * angka tak terduga tidak punya cara menelusurinya.
     *
     * @return array{0: float, 1: string}
     */
    private function persenUntuk(Kontrak $kontrak): array
    {
        $unit = $kontrak->unit;
        $tahap = $unit?->tahap_id ? Tahap::with('lokasi')->find($unit->tahap_id) : null;

        if ($tahap !== null && $tahap->persen_batal_admin !== null) {
            return [
                (float) $tahap->persen_batal_admin,
                trim(($tahap->lokasi?->kode ?? '').' '.$tahap->kode),
            ];
        }

        return [(float) $this->setelan->batal_admin_persen, 'bawaan'];
    }
}
