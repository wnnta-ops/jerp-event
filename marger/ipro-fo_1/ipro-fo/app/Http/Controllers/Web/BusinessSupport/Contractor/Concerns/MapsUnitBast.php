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
            'block'       => $u->block?->code ?? '—',
            'number'      => (string) ($attr['nomer'] ?? $attr['number'] ?? ''),
            'type'        => $u->unitType?->code ?? $u->unitType?->name ?? '—',
            'cluster'     => $u->cluster?->name ?? $u->cluster?->code ?? '—',
            'lt'          => $u->unitType?->lt,
            'lb'          => $u->unitType?->lb,
            'price'       => (float) $u->current_price,
            'unit_status' => is_object($status) && method_exists($status, 'label') ? $status->label() : (string) $status,
            'bast_status' => $sudahBast ? 'sudah' : 'belum',
        ];
    }
}
