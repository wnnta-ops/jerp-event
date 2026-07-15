<?php

namespace App\Modules\FO\Shared\Assistant\Entities;

use App\Modules\FO\MarketingSales\Models\KontrakTagihan;
use App\Modules\FO\Shared\Assistant\AbstractSearchableEntity;
use App\Modules\FO\Shared\Assistant\AssistantAccess;
use App\Modules\FO\Shared\Assistant\SearchResult;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * Entitas Tagihan / Tunggakan — jadwal tagihan per kontrak (DP/angsuran/TDP).
 * Default menampilkan yang BELUM lunas. Tenant scope lewat relasi kontrak.
 */
class TagihanEntity extends AbstractSearchableEntity
{
    public function key(): string
    {
        return 'tagihan';
    }

    public function label(): string
    {
        return 'Tagihan / Tunggakan';
    }

    public function permission(): string
    {
        return AssistantAccess::readPermission('kontrak');
    }

    public function hint(): string
    {
        return 'filter: periode jatuh tempo, proyek, status (NUNGGAK/LUNAS), kata kunci no kontrak/nama customer. Default: yang belum lunas';
    }

    public function search(array $f): SearchResult
    {
        $filter = [];
        // whereHas('kontrak') memastikan tenant scope (KontrakTagihan model polos).
        $q = KontrakTagihan::query()->whereHas('kontrak')->with(['kontrak.customer']);

        $dari = $this->parseDate($f['dari'] ?? null);
        $sampai = $this->parseDate($f['sampai'] ?? null);
        if ($dari) {
            $q->whereDate('jatuh_tempo', '>=', $dari);
        }
        if ($sampai) {
            $q->whereDate('jatuh_tempo', '<=', $sampai);
        }
        if ($dari || $sampai) {
            $filter['jatuh_tempo'] = $this->rangeLabel($dari, $sampai);
        }

        if (! empty($f['proyek'])) {
            $p = (string) $f['proyek'];
            $q->whereHas('kontrak.customer.project', fn (Builder $pr) => $pr->where('name', 'like', "%{$p}%")->orWhere('code', 'like', "%{$p}%"));
            $filter['proyek'] = $p;
        }

        $st = $this->status($f);
        if ($st === 'LUNAS') {
            $q->whereColumn('pelunasan', '>=', 'nilai');
            $filter['status'] = 'LUNAS';
        } elseif ($st === 'NUNGGAK') {
            $q->whereColumn('pelunasan', '<', 'nilai')->whereDate('jatuh_tempo', '<', Carbon::today());
            $filter['status'] = 'NUNGGAK';
        } else {
            // default: belum lunas (sisa > 0)
            $q->whereColumn('pelunasan', '<', 'nilai');
            $filter['status'] = 'BELUM LUNAS';
        }

        if (! empty($f['q'])) {
            $kw = (string) $f['q'];
            $q->whereHas('kontrak', fn (Builder $k) => $k->where('no_kontrak', 'like', "%{$kw}%")
                ->orWhereHas('customer', fn (Builder $c) => $c->where('full_name', 'like', "%{$kw}%")));
            $filter['kata_kunci'] = $kw;
        }

        $today = Carbon::today();
        $rows = $q->orderBy('jatuh_tempo')->limit($this->clampLimit($f))->get()
            ->map(function (KontrakTagihan $t) use ($today) {
                $sisa = (float) $t->nilai - (float) $t->pelunasan;
                $status = $sisa <= 0 ? 'Lunas' : ($t->jatuh_tempo && Carbon::parse($t->jatuh_tempo)->lt($today) ? 'Nunggak' : 'Belum jatuh tempo');

                return [
                    'No. Kontrak' => $t->kontrak?->no_kontrak,
                    'Customer' => $t->kontrak?->customer?->full_name,
                    'Tipe' => $t->tipe,
                    'Nama Tagihan' => $t->nama_tagihan,
                    'Jatuh Tempo' => $this->tanggal($t->jatuh_tempo),
                    'Nilai (Rp)' => $this->rupiah($t->nilai),
                    'Terbayar (Rp)' => $this->rupiah($t->pelunasan),
                    'Sisa (Rp)' => $this->rupiah($sisa),
                    'Status' => $status,
                ];
            })->all();

        return new SearchResult(
            ['No. Kontrak', 'Customer', 'Tipe', 'Nama Tagihan', 'Jatuh Tempo', 'Nilai (Rp)', 'Terbayar (Rp)', 'Sisa (Rp)', 'Status'],
            $rows,
            $filter,
        );
    }
}
