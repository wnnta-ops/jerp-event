<?php

namespace App\Modules\FO\MarketingSales\Services;

use App\Modules\FO\Adminjual\Models\Gimmick;
use App\Modules\FO\Adminjual\Models\TipeGimmick;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use App\Modules\FO\MarketingSales\Models\Unit;

/**
 * Paket Gimmick (= master Tipe Gimmick) — pilih satu paket, semua item-nya masuk
 * ke kontrak sebagai gimmick yang DIBERIKAN (tercetak di SPR). Item yang tidak
 * didapat customer tinggal dilepas (Closing) / dimatikan (tab Gimmick SPR).
 * Dipakai bersama oleh Closing NUP & tab Gimmick SPR.
 */
class PaketGimmickService
{
    /** Paket aktif yang berlaku untuk unit: project & cluster cocok, atau kosong (= semua). */
    public function opsiPaket(?Unit $unit): array
    {
        return TipeGimmick::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('project_id')->orWhere('project_id', $unit?->project_id))
            ->where(fn ($q) => $q->whereNull('lokasi_id')->orWhere('lokasi_id', $unit?->lokasi_id))
            ->withCount(['gimmicks' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('nama')
            ->get()
            ->mapWithKeys(fn (TipeGimmick $t) => [$t->id => $t->nama.' ('.$t->gimmicks_count.' item)'])
            ->all();
    }

    /** Item aktif dalam paket: [gimmick_id => nama]. */
    public function itemPaket(?int $tipeId): array
    {
        if (! $tipeId) {
            return [];
        }

        return Gimmick::query()
            ->where('tipe_gimmick_id', $tipeId)
            ->where('is_active', true)
            ->orderBy('code')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Tandai gimmick terpilih sebagai DIBERIKAN di kontrak. Baris yang sudah ada
     * (mis. dibentuk otomatis dari master saat SPR terbentuk) diaktifkan, yang
     * belum ada dibuat. Gimmick lain tidak disentuh. @return int jumlah item.
     */
    public function berikan(Kontrak $kontrak, array $gimmickIds): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $gimmickIds))));
        if (! $ids) {
            return 0;
        }

        $urut = (int) ($kontrak->gimmicks()->max('urutan') ?? -1);
        $n = 0;
        foreach (Gimmick::whereIn('id', $ids)->get() as $g) {
            $baris = $kontrak->gimmicks()->where('gimmick_id', $g->id)->first();
            if ($baris) {
                $baris->update(['diberikan' => true]);
            } else {
                $kontrak->gimmicks()->create([
                    'gimmick_id' => $g->id,
                    'nama_barang' => $g->name,
                    'diberikan' => true,
                    'diterima' => false,
                    'qty' => 1,
                    'harga_satuan' => (float) $g->nilai,
                    'total' => (float) $g->nilai,
                    'urutan' => ++$urut,
                ]);
            }
            $n++;
        }

        return $n;
    }
}
