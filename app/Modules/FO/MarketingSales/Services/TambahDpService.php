<?php

namespace App\Modules\FO\MarketingSales\Services;

use App\Modules\FO\MarketingSales\Models\Kontrak;

/**
 * Tambah DP (kontrak KPR) — DP tambahan dari customer yang OTOMATIS mengurangi
 * Pencairan KPR, total tagihan tetap = harga unit. Dipakai bersama oleh tombol
 * "Tambah DP" di Edit SPR dan form Closing NUP, supaya hitungannya satu sumber.
 */
class TambahDpService
{
    /** Tambah satu baris TDP lalu sesuaikan Pencairan KPR. */
    public function tambah(Kontrak $kontrak, float $nominal, mixed $jatuhTempo = null): void
    {
        if ($nominal <= 0) {
            return;
        }
        $urut = (int) ($kontrak->tagihan()->max('urutan') ?? 0) + 1;
        $kontrak->tagihan()->create([
            'urutan' => max(60, $urut),
            'tipe' => 'TDP',
            'nama_tagihan' => 'TAMBAH DP',
            'jatuh_tempo' => $jatuhTempo ?: now(),
            'nilai' => $nominal,
            'pelunasan' => 0,
        ]);
        // Data lama: Pencairan KPR dikurangi sebesar Tambah DP (bukan dihitung ulang dari harga).
        $kontrak->dariDataLama()
            ? $this->geserPencairanKpr($kontrak, -$nominal)
            : $this->recomputePencairanKpr($kontrak);
    }

    /** Hapus semua baris Tambah DP & kembalikan Pencairan KPR. */
    public function batal(Kontrak $kontrak): void
    {
        $tdp = (float) $kontrak->tagihan()->where('tipe', 'TDP')->sum('nilai');
        $kontrak->tagihan()->where('tipe', 'TDP')->delete();
        $kontrak->dariDataLama()
            ? $this->geserPencairanKpr($kontrak, $tdp)
            : $this->recomputePencairanKpr($kontrak);
    }

    /**
     * Pencairan KPR = harga − seluruh tagihan lain (tanpa GMC). Hanya kontrak KPR
     * non-data-lama. True bila nilainya berubah.
     */
    public function recomputePencairanKpr(Kontrak $kontrak): bool
    {
        if ($kontrak->dariDataLama() || strtoupper((string) $kontrak->cara_bayar) !== 'KPR') {
            return false;
        }
        $tagihan = $kontrak->tagihan()->get();
        $kpr = $tagihan->first(fn ($t) => $t->tipe === 'ANG' && str_contains(strtoupper((string) $t->nama_tagihan), 'PENCAIRAN'))
            ?? $tagihan->first(fn ($t) => $t->tipe === 'ANG');
        if (! $kpr) {
            return false;
        }
        $harga = (float) $kontrak->total_kontrak;
        $lain = $tagihan->reject(fn ($t) => $t->id === $kpr->id || $t->tipe === 'GMC')
            ->sum(fn ($t) => (float) $t->nilai);
        $baru = max(0, round($harga - $lain));

        if (abs((float) $kpr->nilai - $baru) >= 1) {
            $kpr->update(['nilai' => $baru]);

            return true;
        }

        return false;
    }

    /** Data lama: tambah/kurangi nilai baris Pencairan KPR sebesar $selisih (tanpa hitung ulang dari harga). */
    public function geserPencairanKpr(Kontrak $kontrak, float $selisih): void
    {
        $tagihan = $kontrak->tagihan()->get();
        $kpr = $tagihan->first(fn ($t) => $t->tipe === 'ANG' && str_contains(strtoupper((string) $t->nama_tagihan), 'PENCAIRAN'))
            ?? $tagihan->first(fn ($t) => $t->tipe === 'ANG');
        $kpr?->update(['nilai' => max(0, round((float) $kpr->nilai + $selisih))]);
    }
}
