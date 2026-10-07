<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Services;

use App\Domain\BusinessSupport\Models\BsMukContract;
use App\Domain\BusinessSupport\Models\BsProjectMuk;
use Illuminate\Support\Collection;

/**
 * Kolom nilai sebuah SPK MUK — SATU definisi untuk Kontrak SPK (MUK) dan Informasi CTC.
 *
 * Kedua layar menampilkan No. SPK, Lokasi, Tahap, dan nilai SPK yang SAMA. Bila masing-masing
 * menyusunnya sendiri, suatu saat keduanya berbeda untuk kontrak yang sama — dan tidak ada
 * yang tahu mana yang benar. Itu sebabnya ia di sini, bukan disalin dua kali.
 *
 * ── LOKASI & TAHAP TIDAK DATANG DARI MUK ──
 *
 * MUK hanya punya "proyek", yang sebenarnya paket pekerjaan. Keduanya diturunkan dari
 * pemetaan di Setup Project: kode proyek MUK → project kita → cluster & tahap yang diisi
 * operator di sana.
 *
 * ── PPN & PPh 4(2) BELUM DIKIRIM MUK ──
 *
 * Endpoint kontrak MUK tidak memuat keduanya; yang ada hanya per tagihan, dan itu nilai yang
 * SUDAH DITAGIH — bukan nilai SPK. Menjumlahkannya dari sana memberi angka yang tampak masuk
 * akal untuk hal yang berbeda. Menghitungnya sendiri dari DPP juga ditolak: PPh 4(2) jasa
 * konstruksi bertarif 1,75%–4% menurut kualifikasi pelaksana, dan satu tarif tetap akan salah
 * untuk sebagian kontrak tanpa ada yang bisa melihatnya.
 *
 * Keduanya null — bukan 0. Nol adalah angka, dan orang akan memakainya.
 */
class BarisSpkMuk
{
    /**
     * Peta kode proyek MUK → cluster, tahap, kode project kita.
     *
     * Diambil SEKALI lalu dioper ke tiap baris, bukan satu kueri per baris: daftar
     * pemetaannya pendek sedangkan tabelnya berpuluh baris per halaman.
     *
     * @return Collection<string, object>
     */
    public function petaProyek(): Collection
    {
        return BsProjectMuk::query()
            ->join('bs_projects', 'bs_projects.id', '=', 'bs_project_muk.project_id')
            ->get(['bs_project_muk.muk_proyek_kode', 'bs_projects.cluster', 'bs_projects.tahap', 'bs_projects.code'])
            ->keyBy('muk_proyek_kode');
    }

    /**
     * Kolom nilai satu SPK, sesuai Lampiran 2.
     *
     * @param  object|null  $proyek  baris dari petaProyek(), atau null bila belum dipetakan
     * @return array<string, mixed>
     */
    public function kolom(BsMukContract $k, ?object $proyek): array
    {
        // DPP = nilai_kontrak. Dokumen MUK menyebutnya tegas: "nilai_kontrak: DPP (sebelum
        // PPN)". Bukan nama kedua untuk hal yang sama — dipisah supaya kolom di layar sejajar
        // dengan Lampiran 2 dan tidak perlu ditafsirkan.
        $dpp = (float) $k->nilai_kontrak;
        $ppn = null;
        $pph = null;

        // Nilai yang ditagihkan = DPP + PPN − PPh 4(2). Selama MUK belum mengirim keduanya,
        // ia sama dengan DPP — dan layar WAJIB mengatakan itu, bukan menyodorkannya sebagai
        // angka final. Begitu MUK membuka kedua nilainya, baris ini ikut bergeser sendiri.
        $tagihan = $dpp + (float) ($ppn ?? 0) - (float) ($pph ?? 0);

        // Yang sudah ditagih menurut MUK sendiri (kumulatif, neto) — bukan hitungan kita.
        $sudah = (float) $k->kum_ditagih_neto;

        return [
            'no_spk' => $k->no_spk,
            'lokasi' => $proyek?->cluster,
            'tahap' => $proyek?->tahap,
            'project_kode' => $proyek?->code,
            'spk_dpp' => $dpp,
            'spk_ppn' => $ppn,
            'spk_pph42' => $pph,
            'spk_tagihan' => $tagihan,
            'sudah_tertagih' => $sudah,
            // SISA NILAI SPK, bukan sisa yang layak ditagih sekarang. Dua hal yang berbeda:
            // BsMukContract::belum_ditagih memakai OPNAME dikurangi ditagih (berapa yang sudah
            // dikerjakan tapi belum ditagihkan), sedangkan kolom Lampiran 2 ini memakai NILAI
            // SPK dikurangi ditagih (berapa sisa kontraknya) — itulah arti CTC.
            //
            // Dijaga tidak negatif dengan alasan yang sama seperti di model: pada sebagian
            // kontrak MUK, ditagih melampaui nilai kontraknya. Sisa negatif di layar mengundang
            // orang mengurangi tagihan yang sudah benar; yang seperti itu perlu ditanyakan ke
            // MUK, bukan dihitung sendiri di sini.
            'belum_tertagih' => max(0.0, $tagihan - $sudah),
            // Benar bila angka MUK sendiri tidak masuk akal — ditandai, bukan disembunyikan.
            'janggal_tertagih' => $sudah > $tagihan,
        ];
    }
}
