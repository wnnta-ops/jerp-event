<?php

namespace App\Modules\FO\Shared\Assistant\Entities;

use App\Modules\FO\Shared\Assistant\AbstractSearchableEntity;
use App\Modules\FO\Shared\Assistant\AssistantAccess;
use App\Modules\FO\Shared\Assistant\SearchResult;
use Illuminate\Database\Eloquent\Builder;

/**
 * Entitas generik berbasis konfigurasi — dipakai untuk sebagian besar data FO
 * agar tak perlu kelas per tabel. Query READ-ONLY lewat model Eloquent (tenant
 * scope otomatis). Entitas yang butuh logika khusus (Customer/Kontrak/Tagihan)
 * tetap punya kelas sendiri.
 */
class GenericEntity extends AbstractSearchableEntity
{
    /**
     * @param  array<string,array{0:string,1?:string}>  $columns  label => [accessorPath, format? text|date|rupiah|bool]
     * @param  array<int,string>  $searchColumns  kolom untuk filter q (boleh "relasi.kolom")
     * @param  array<int,string>  $with  relasi eager-load
     */
    public function __construct(
        private readonly string $keyName,
        private readonly string $labelName,
        private readonly string $permissionSlug,
        private readonly string $modelClass,
        private readonly array $columns,
        private readonly ?string $dateColumn = null,
        private readonly ?string $statusColumn = null,
        private readonly array $searchColumns = [],
        private readonly array $with = [],
        private readonly ?string $projectVia = null,
        private readonly string $hintExtra = '',
    ) {}

    public function key(): string
    {
        return $this->keyName;
    }

    public function label(): string
    {
        return $this->labelName;
    }

    public function permission(): string
    {
        return AssistantAccess::readPermission($this->permissionSlug);
    }

    public function hint(): string
    {
        $parts = [];
        if ($this->dateColumn) {
            $parts[] = 'periode';
        }
        if ($this->projectVia) {
            $parts[] = 'proyek';
        }
        if ($this->statusColumn) {
            $parts[] = 'status';
        }
        if ($this->searchColumns) {
            $parts[] = 'kata kunci';
        }

        return 'filter: '.implode(', ', $parts).($this->hintExtra !== '' ? '. '.$this->hintExtra : '');
    }

    public function search(array $f): SearchResult
    {
        $filter = [];
        /** @var Builder $q */
        $q = $this->modelClass::query();
        if ($this->with !== []) {
            $q->with($this->with);
        }

        if ($this->dateColumn) {
            $dari = $this->parseDate($f['dari'] ?? null);
            $sampai = $this->parseDate($f['sampai'] ?? null);
            if ($dari) {
                $q->whereDate($this->dateColumn, '>=', $dari);
            }
            if ($sampai) {
                $q->whereDate($this->dateColumn, '<=', $sampai);
            }
            if ($dari || $sampai) {
                $filter['periode'] = $this->rangeLabel($dari, $sampai);
            }
        }

        if ($this->projectVia && ! empty($f['proyek'])) {
            $p = (string) $f['proyek'];
            $q->whereHas($this->projectVia, fn (Builder $pr) => $pr->where('name', 'like', "%{$p}%")->orWhere('code', 'like', "%{$p}%"));
            $filter['proyek'] = $p;
        }

        if ($this->statusColumn && ($st = $this->status($f)) !== '') {
            $q->where($this->statusColumn, $st);
            $filter['status'] = $st;
        }

        if ($this->searchColumns !== [] && ! empty($f['q'])) {
            $kw = (string) $f['q'];
            $q->where(function (Builder $w) use ($kw) {
                foreach ($this->searchColumns as $col) {
                    if (str_contains($col, '.')) {
                        [$rel, $c] = explode('.', $col, 2);
                        $w->orWhereHas($rel, fn (Builder $r) => $r->where($c, 'like', "%{$kw}%"));
                    } else {
                        $w->orWhere($col, 'like', "%{$kw}%");
                    }
                }
            });
            $filter['kata_kunci'] = $kw;
        }

        $order = $this->dateColumn ?: 'id';
        $rows = $q->orderByDesc($order)->limit($this->clampLimit($f))->get()
            ->map(fn ($m) => $this->row($m))->all();

        return new SearchResult(array_keys($this->columns), $rows, $filter);
    }

    /** @return array<string,mixed> */
    private function row(mixed $m): array
    {
        $out = [];
        foreach ($this->columns as $label => $spec) {
            $val = data_get($m, $spec[0]);
            $out[$label] = match ($spec[1] ?? 'text') {
                'date' => $this->tanggal($val),
                'rupiah' => $this->rupiah($val),
                'bool' => $val ? 'Ya' : 'Tidak',
                default => $val,
            };
        }

        return $out;
    }
}
