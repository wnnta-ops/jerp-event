<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Contractor\Concerns;

use App\Domain\Legal\Bast\Models\BastDocument;
use App\Domain\Master\Unit\Models\Unit;

/**
 * Helper bersama: status "sudah/belum BAST" per unit + pemetaan unit master ke
 * kolom tampilan Generate BAST / detail Setup Project (sesuai lampiran daftar unit).
 */
trait MapsUnitBast
{
    /**
     * Kembalikan id unit (dari daftar $unitIds) yang SUDAH punya dokumen BAST
     * (melalui booking → bast_documents). Sumber status BAST tunggal se-aplikasi.
     *
     * @param  array<int,int>  $unitIds
     * @return array<int,int>
     */
    protected function bastUnitIds(array $unitIds): array
    {
        if ($unitIds === []) {
            return [];
        }

        return BastDocument::query()
            ->join('bookings', 'bookings.id', '=', 'bast_documents.booking_id')
            ->whereIn('bookings.unit_id', $unitIds)
            ->pluck('bookings.unit_id')
            ->map(fn ($v) => (int) $v)
            ->unique()->values()->all();
    }

    /**
     * Petakan Unit master (dengan relasi cluster/block/unitType/project) ke baris
     * tampilan 12 kolom lampiran + status BAST.
     *
     * @return array<string,mixed>
     */
    protected function mapUnitRow(Unit $u, bool $sudahBast): array
    {
        $attr   = $u->getAttribute('attributes');
        $attr   = is_array($attr) ? $attr : [];
        $status = $u->status;

        return [
            'source'      => 'unit',
            'unit_id'     => $u->id,
            'project'     => $u->project?->code ?? $u->project?->name ?? '—',
            'unit_no'     => $u->unit_code,
            'location'    => $u->address ?: ($u->project?->location ?? '—'),
            'phase'       => (string) ($attr['tahap'] ?? $attr['phase'] ?? ''),
            // Blok: relasi block_id TIDAK PERNAH diisi oleh IproMasterSync — bloknya ikut
            // ke `attributes` saja. Tanpa cadangan ini seluruh kolom Blok terbaca "—"
            // padahal datanya ada, hanya di tempat lain.
            'block'       => $u->block?->code ?: ($attr['blok'] ?? $attr['block'] ?? '—'),
            'number'      => (string) ($attr['nomer'] ?? $attr['number'] ?? ''),
            // NAMA dulu, baru kode. Kode tipe di cermin BO adalah "FOT-{id}" yang dikarang
            // sinkron — tidak berarti apa pun bagi orang yang membacanya. Namanya ("CENDANA")
            // yang sebenarnya dikenal di lapangan.
            'type'        => $u->unitType?->name ?: ($u->unitType?->code ?? '—'),
            'cluster'     => $u->cluster?->name ?? $u->cluster?->code ?? '—',
            // Luas diambil dari UNITNYA dulu, baru dari master tipe: dua unit bertipe sama
            // bisa berbeda luas tanahnya (unit pojok, unit tusuk sate), dan angka tipe akan
            // menyeragamkan sesuatu yang memang tidak seragam.
            'lt'          => $attr['luas_tanah'] ?? $u->unitType?->lt,
            'lb'          => $attr['luas_bangunan'] ?? $u->unitType?->lb,
            'price'       => (float) $u->current_price,
            'unit_status' => is_object($status) && method_exists($status, 'label') ? $status->label() : (string) $status,
            'bast_status' => $sudahBast ? 'sudah' : 'belum',
        ];
    }
}
