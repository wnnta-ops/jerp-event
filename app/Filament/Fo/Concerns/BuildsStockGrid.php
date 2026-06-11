<?php

namespace App\Filament\Fo\Concerns;

use App\Modules\FO\MarketingSales\Models\Lokasi;
use App\Modules\FO\MarketingSales\Models\Unit;

/**
 * Tabel stok visual (grid Blok × No. unit, warna sesuai status) — dipakai Pilih Unit
 * Booked & Pindah Unit. Halaman pemakai: simpan property $lokasiId & $tahap (live),
 * implement selectGridUnit($id), dan render partial 'filament.fo.partials.stock-grid'.
 */
trait BuildsStockGrid
{
    /** @return array<int|string,string> opsi cluster (Lokasi) untuk project. */
    public function clusterOptions(?int $projectId): array
    {
        return Lokasi::query()
            ->when($projectId, fn ($q) => $q->where('project_id', $projectId))
            ->orderBy('nama')->pluck('nama', 'id')->all();
    }

    /** @return array<string,string> opsi tahap (dari unit di cluster terpilih). */
    public function tahapOptions(?int $projectId, ?int $lokasiId): array
    {
        if (! $lokasiId) {
            return [];
        }

        return Unit::query()->where('is_active', true)
            ->when($projectId, fn ($q) => $q->where('project_id', $projectId))
            ->where('lokasi_id', $lokasiId)
            ->whereNotNull('tahap')->where('tahap', '!=', '')
            ->distinct()->orderBy('tahap')->pluck('tahap', 'tahap')->all();
    }

    /**
     * Data grid: rows (blok), cols (no_unit), cells map "blok|no" => {id,status,no}.
     *
     * @return array{rows:array<int,string>,cols:array<int,string>,cells:array<string,array{id:int,status:string,no:string}>,total:int}
     */
    public function stockGridData(?int $projectId, ?int $lokasiId, ?string $tahap, ?int $excludeUnitId = null): array
    {
        if (! $lokasiId) {
            return ['rows' => [], 'cols' => [], 'cells' => [], 'total' => 0];
        }

        $units = Unit::query()->where('is_active', true)
            ->when($projectId, fn ($q) => $q->where('project_id', $projectId))
            ->where('lokasi_id', $lokasiId)
            ->when($tahap, fn ($q) => $q->where('tahap', $tahap))
            ->get(['id', 'blok', 'no_unit', 'status', 'harga_jual']);

        $rows = $units->map(fn ($u) => (string) $u->blok)->unique()->sort()->reverse()->values()->all();
        $cols = $units->map(fn ($u) => (string) $u->no_unit)->unique()->sort()->values()->all();

        $cells = [];
        foreach ($units as $u) {
            $cells[((string) $u->blok).'|'.((string) $u->no_unit)] = [
                'id' => (int) $u->id,
                'status' => $excludeUnitId === (int) $u->id ? 'INI' : (string) $u->status,
                'no' => (string) $u->no_unit,
                'harga' => (float) $u->harga_jual,
            ];
        }

        return ['rows' => $rows, 'cols' => $cols, 'cells' => $cells, 'total' => $units->count()];
    }
}
