<?php

namespace App\Modules\FO\Shared\Assistant\Entities;

use App\Modules\FO\MarketingSales\Models\Kontrak;
use App\Modules\FO\Shared\Assistant\AbstractSearchableEntity;
use App\Modules\FO\Shared\Assistant\AssistantAccess;
use App\Modules\FO\Shared\Assistant\SearchResult;
use Illuminate\Database\Eloquent\Builder;

/**
 * Entitas Kontrak / SPR — daftar kontrak (SPRS = booking, SPR = final).
 */
class KontrakEntity extends AbstractSearchableEntity
{
    public function key(): string
    {
        return 'kontrak';
    }

    public function label(): string
    {
        return 'Kontrak / SPR';
    }

    public function permission(): string
    {
        return AssistantAccess::readPermission('kontrak');
    }

    public function hint(): string
    {
        return 'filter: periode tgl kontrak, proyek, status (SPRS/booking, SPR/final, BATAL), kata kunci no kontrak/nama customer';
    }

    public function search(array $f): SearchResult
    {
        $filter = [];
        $q = Kontrak::query()->with(['customer.project', 'unit']);

        $dari = $this->parseDate($f['dari'] ?? null);
        $sampai = $this->parseDate($f['sampai'] ?? null);
        if ($dari) {
            $q->whereDate('tgl_kontrak', '>=', $dari);
        }
        if ($sampai) {
            $q->whereDate('tgl_kontrak', '<=', $sampai);
        }
        if ($dari || $sampai) {
            $filter['periode'] = $this->rangeLabel($dari, $sampai);
        }

        if (! empty($f['proyek'])) {
            $p = (string) $f['proyek'];
            $q->whereHas('customer.project', fn (Builder $pr) => $pr->where('name', 'like', "%{$p}%")->orWhere('code', 'like', "%{$p}%"));
            $filter['proyek'] = $p;
        }

        $st = $this->status($f);
        if ($st !== '') {
            match ($st) {
                'BOOKING', 'SPRS' => $q->where('tahap', 'SPRS'),
                'SPR' => $q->where('tahap', 'SPR'),
                'BATAL' => $q->where('status', 'BATAL'),
                default => null,
            };
            $filter['status'] = $st === 'BOOKING' ? 'SPRS' : $st;
        }

        if (! empty($f['q'])) {
            $kw = (string) $f['q'];
            $q->where(fn (Builder $w) => $w->where('no_kontrak', 'like', "%{$kw}%")
                ->orWhereHas('customer', fn (Builder $c) => $c->where('full_name', 'like', "%{$kw}%")));
            $filter['kata_kunci'] = $kw;
        }

        $rows = $q->latest('tgl_kontrak')->limit($this->clampLimit($f))->get()
            ->map(fn (Kontrak $k) => [
                'No. Kontrak' => $k->no_kontrak,
                'Tgl' => $this->tanggal($k->tgl_kontrak),
                'Customer' => $k->customer?->full_name,
                'Unit' => $k->unit?->kode_unit,
                'Cara Bayar' => $k->cara_bayar,
                'Tahap' => $k->tahap === 'SPR' ? 'SPR (Final)' : 'Booking (SPRS)',
                'Status' => $k->status,
                'Total (Rp)' => $this->rupiah($k->total_kontrak),
            ])->all();

        return new SearchResult(
            ['No. Kontrak', 'Tgl', 'Customer', 'Unit', 'Cara Bayar', 'Tahap', 'Status', 'Total (Rp)'],
            $rows,
            $filter,
        );
    }
}
