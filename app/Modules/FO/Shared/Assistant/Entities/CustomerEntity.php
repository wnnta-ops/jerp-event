<?php

namespace App\Modules\FO\Shared\Assistant\Entities;

use App\Modules\FO\MarketingSales\Models\Customer;
use App\Modules\FO\Shared\Assistant\AbstractSearchableEntity;
use App\Modules\FO\Shared\Assistant\AssistantAccess;
use App\Modules\FO\Shared\Assistant\SearchResult;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * Entitas Customer — data customer FO beserta ringkasan booking/kontraknya.
 */
class CustomerEntity extends AbstractSearchableEntity
{
    public function key(): string
    {
        return 'customer';
    }

    public function label(): string
    {
        return 'Customer';
    }

    public function permission(): string
    {
        return AssistantAccess::readPermission('customer');
    }

    public function hint(): string
    {
        return 'filter: periode booking, proyek, status (BOOKING/SPR/BATAL/LUNAS/NUNGGAK), kata kunci nama/HP/kode';
    }

    public function search(array $f): SearchResult
    {
        $filter = [];
        $q = Customer::query()->with(['project', 'kontrak.unit']);

        $dari = $this->parseDate($f['dari'] ?? null);
        $sampai = $this->parseDate($f['sampai'] ?? null);
        if ($dari || $sampai) {
            $q->whereHas('kontrak', function (Builder $k) use ($dari, $sampai) {
                if ($dari) {
                    $k->whereDate('tgl_kontrak', '>=', $dari);
                }
                if ($sampai) {
                    $k->whereDate('tgl_kontrak', '<=', $sampai);
                }
            });
            $filter['periode_booking'] = $this->rangeLabel($dari, $sampai);
        }

        if (! empty($f['proyek'])) {
            $p = (string) $f['proyek'];
            $q->whereHas('project', fn (Builder $pr) => $pr->where('name', 'like', "%{$p}%")->orWhere('code', 'like', "%{$p}%"));
            $filter['proyek'] = $p;
        }

        $st = $this->status($f);
        if ($st !== '') {
            match ($st) {
                'BOOKING', 'SPRS' => $q->whereHas('kontrak', fn (Builder $k) => $k->where('tahap', 'SPRS')),
                'SPR' => $q->whereHas('kontrak', fn (Builder $k) => $k->where('tahap', 'SPR')),
                'BATAL' => $q->whereHas('kontrak', fn (Builder $k) => $k->where('status', 'BATAL')),
                'NUNGGAK' => $q->whereHas('kontrak', fn (Builder $k) => $k->whereHas('tagihan', fn (Builder $t) => $t->whereColumn('pelunasan', '<', 'nilai')->whereDate('jatuh_tempo', '<', Carbon::today()))),
                'LUNAS' => $q->whereHas('kontrak')->whereDoesntHave('kontrak', fn (Builder $k) => $k->whereHas('tagihan', fn (Builder $t) => $t->whereColumn('pelunasan', '<', 'nilai'))),
                default => null,
            };
            $filter['status'] = $st === 'SPRS' ? 'BOOKING' : $st;
        }

        if (! empty($f['q'])) {
            $kw = (string) $f['q'];
            $q->where(fn (Builder $w) => $w->where('full_name', 'like', "%{$kw}%")
                ->orWhere('phone', 'like', "%{$kw}%")->orWhere('mobile', 'like', "%{$kw}%")
                ->orWhere('code', 'like', "%{$kw}%")->orWhere('email', 'like', "%{$kw}%"));
            $filter['kata_kunci'] = $kw;
        }

        $rows = $q->latest('id')->limit($this->clampLimit($f))->get()
            ->map(fn (Customer $c) => $this->row($c))->all();

        return new SearchResult($this->columns(), $rows, $filter);
    }

    /** @return array<int,string> */
    private function columns(): array
    {
        return ['No. Customer', 'Nama', 'No. HP', 'Email', 'Proyek', 'Lead', 'Jml Booking', 'Booking Terakhir', 'Unit', 'Tgl Booking', 'Status', 'Total Nilai (Rp)'];
    }

    /** @return array<string,mixed> */
    private function row(Customer $c): array
    {
        $last = $c->kontrak->sortByDesc('tgl_kontrak')->first();

        return [
            'No. Customer' => $c->code,
            'Nama' => $c->full_name,
            'No. HP' => $c->mobile ?: $c->phone,
            'Email' => $c->email,
            'Proyek' => $c->project?->name,
            'Lead' => $c->lead_status,
            'Jml Booking' => $c->kontrak->count(),
            'Booking Terakhir' => $last?->no_kontrak,
            'Unit' => $last?->unit?->kode_unit,
            'Tgl Booking' => $this->tanggal($last?->tgl_kontrak),
            'Status' => $last ? ($last->tahap === 'SPR' ? 'SPR (Final)' : 'Booking') : null,
            'Total Nilai (Rp)' => $this->rupiah($c->kontrak->sum('total_kontrak')),
        ];
    }
}
