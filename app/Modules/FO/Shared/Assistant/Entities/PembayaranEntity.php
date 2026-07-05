<?php

namespace App\Modules\FO\Shared\Assistant\Entities;

use App\Modules\FO\FinanceAR\Models\Tts;
use App\Modules\FO\Shared\Assistant\AbstractSearchableEntity;
use App\Modules\FO\Shared\Assistant\AssistantAccess;
use App\Modules\FO\Shared\Assistant\SearchResult;
use Illuminate\Database\Eloquent\Builder;

/**
 * Entitas Pembayaran / TTS — Tanda Terima Sementara (uang masuk FO).
 */
class PembayaranEntity extends AbstractSearchableEntity
{
    public function key(): string
    {
        return 'pembayaran';
    }

    public function label(): string
    {
        return 'Pembayaran / TTS';
    }

    public function permission(): string
    {
        return AssistantAccess::readPermission('tts');
    }

    public function hint(): string
    {
        return 'filter: periode tgl TTS, proyek, status, kata kunci no TTS/nama customer';
    }

    public function search(array $f): SearchResult
    {
        $filter = [];
        $q = Tts::query()->with(['customer.project']);

        $dari = $this->parseDate($f['dari'] ?? null);
        $sampai = $this->parseDate($f['sampai'] ?? null);
        if ($dari) {
            $q->whereDate('tgl_tts', '>=', $dari);
        }
        if ($sampai) {
            $q->whereDate('tgl_tts', '<=', $sampai);
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
            $q->where('status', $st);
            $filter['status'] = $st;
        }

        if (! empty($f['q'])) {
            $kw = (string) $f['q'];
            $q->where(fn (Builder $w) => $w->where('no_tts', 'like', "%{$kw}%")
                ->orWhereHas('customer', fn (Builder $c) => $c->where('full_name', 'like', "%{$kw}%")));
            $filter['kata_kunci'] = $kw;
        }

        $rows = $q->latest('tgl_tts')->limit($this->clampLimit($f))->get()
            ->map(fn (Tts $t) => [
                'No. TTS' => $t->no_tts,
                'Tgl' => $this->tanggal($t->tgl_tts),
                'Customer' => $t->customer?->full_name,
                'Jenis' => $t->jenis_pembayaran,
                'Cara Bayar' => $t->cara_bayar,
                'Nominal (Rp)' => $this->rupiah($t->amount),
                'Status' => $t->status,
            ])->all();

        return new SearchResult(
            ['No. TTS', 'Tgl', 'Customer', 'Jenis', 'Cara Bayar', 'Nominal (Rp)', 'Status'],
            $rows,
            $filter,
        );
    }
}
