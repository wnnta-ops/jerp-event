<?php

namespace App\Filament\Fo\Widgets\Concerns;

use Illuminate\Support\Carbon;

/**
 * Filter BULAN & TAHUN di pojok kanan atas panel grafik.
 *
 * ChartWidget hanya menyediakan SATU <select> (slot headerEnd), jadi bulan dan tahun disatukan
 * menjadi satu daftar periode — "September 2026", "Agustus 2026", dan seterusnya. Dua kontrol
 * terpisah menuntut penggantian view bawaan Filament, dan itu berarti bergantung pada markup
 * internal yang bukan API publik hanya demi memisahkan dua angka yang selalu dipilih bersama.
 *
 * Daftarnya dibatasi dari bulan PERTAMA yang benar-benar ada datanya sampai bulan berjalan.
 * Menawarkan periode yang mustahil berisi apa pun hanya membuat orang mengira laporannya rusak
 * ketika grafiknya kosong.
 */
trait FilterPeriode
{
    /** Bulan paling awal yang punya data — null bila belum ada data sama sekali. */
    abstract protected function bulanPertama(): ?Carbon;

    public function mount(): void
    {
        // Harus disetel SEBELUM parent::mount(), yang langsung menghitung checksum datanya.
        $this->filter ??= now()->format('Y-m');
        parent::mount();
    }

    /** @return array<string,string> */
    protected function getFilters(): ?array
    {
        $kini = now()->startOfMonth();
        $awal = $this->bulanPertama()?->startOfMonth() ?? $kini->copy();
        if ($awal->gt($kini)) {
            $awal = $kini->copy();
        }

        $out = [];
        for ($b = $kini->copy(); $b->gte($awal); $b->subMonth()) {
            $out[$b->format('Y-m')] = $b->translatedFormat('F Y');
        }

        return $out;
    }

    /**
     * Periode yang sedang dipilih. Nilai asing atau kosong jatuh ke bulan berjalan — bukan
     * melempar galat: $filter datang dari <select> di peramban dan bisa berisi apa saja.
     */
    protected function periode(): Carbon
    {
        $pilihan = (string) $this->filter;

        try {
            $t = Carbon::createFromFormat('Y-m-d', $pilihan.'-01')->startOfMonth();
        } catch (\Throwable) {
            return now()->startOfMonth();
        }

        // Pembacaan tanggal PHP MENGGULUNG bulan di luar 1–12 alih-alih menolaknya: '2026-13'
        // menjadi Januari 2027, dan '2026-00' menjadi Desember 2025. Keduanya tanggal yang sah,
        // jadi tidak ada galat yang muncul — panelnya hanya memampangkan periode yang tidak
        // pernah dipilih siapa pun. Hasilnya dibandingkan balik untuk menangkap itu.
        return $t->format('Y-m') === $pilihan ? $t : now()->startOfMonth();
    }

    protected function judulPeriode(): string
    {
        return $this->periode()->translatedFormat('F Y');
    }
}
