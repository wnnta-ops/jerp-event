<?php

namespace App\Modules\FO\Adminjual\Exports;

use App\Modules\FO\Adminjual\Models\Skema;
use App\Modules\FO\MarketingSales\Models\Unit;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Template Excel Upload Price List — format LEBAR (satu baris per unit, satu
 * kolom harga per skema). Header & contoh dibangun dinamis dari skema aktif +
 * beberapa kode unit nyata supaya operator langsung tahu formatnya.
 */
class PriceListTemplateExport implements FromArray, ShouldAutoSize, WithHeadings, WithTitle
{
    public function __construct(private readonly int $tenantId = 1) {}

    /** @return array<int,string> */
    public function headings(): array
    {
        $cols = ['No Unit'];
        foreach ($this->schemes() as $s) {
            $cols[] = 'Harga '.$s->code;
        }

        return $cols;
    }

    /** @return array<int,array<int,string|float>> */
    public function array(): array
    {
        $schemes = $this->schemes();

        // Ambil sampai 3 kode unit nyata sebagai contoh (kalau ada).
        $sampleUnits = Unit::query()
            ->where('tenant_id', $this->tenantId)
            ->whereNotNull('kode_unit')
            ->orderBy('kode_unit')
            ->limit(3)
            ->pluck('kode_unit')
            ->all();

        if (empty($sampleUnits)) {
            $sampleUnits = ['JRK3-004-XV-2', 'JRK3-004-XV-3A'];
        }

        // Harga contoh berbeda per skema biar jelas tiap kolom diisi.
        $base = 600000000;
        $rows = [];
        foreach ($sampleUnits as $i => $kode) {
            $row = [$kode];
            foreach ($schemes as $j => $s) {
                $row[] = $base + ($i * 5000000) + ($j * 20000000);
            }
            $rows[] = $row;
        }

        return $rows;
    }

    public function title(): string
    {
        return 'PRICELIST';
    }

    private function schemes()
    {
        // Template fokus ke skema yang TAMPIL di site plan (CASH/KPR/INHOUSE).
        // Skema lain tetap bisa diimpor bila operator menambah kolomnya manual.
        $q = Skema::query()
            ->where('tenant_id', $this->tenantId)
            ->where('is_active', true);

        $tampil = (clone $q)->where('tampil_siteplan', true)->orderBy('code')->get(['code', 'name', 'tampil_siteplan']);

        // Fallback: kalau belum ada yang dicentang, pakai semua skema aktif.
        return $tampil->isNotEmpty() ? $tampil : $q->orderBy('code')->get(['code', 'name', 'tampil_siteplan']);
    }
}
