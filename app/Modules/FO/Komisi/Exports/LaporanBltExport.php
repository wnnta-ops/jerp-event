<?php

namespace App\Modules\FO\Komisi\Exports;

use App\Modules\FO\Komisi\Services\LaporanKomisiBlt;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/** Laporan BLT → Excel: sheet FORMAT BLT SUMMARY + FORMAT BLT DETAIL-Cx per cluster. */
class LaporanBltExport implements WithMultipleSheets
{
    public function __construct(private readonly ?string $from, private readonly ?string $to) {}

    public function sheets(): array
    {
        $rekap = LaporanKomisiBlt::bltRekap($this->from, $this->to);
        $sheets = [new LaporanBltSheet('FORMAT BLT SUMMARY', 'fo.laporan.blt-rekap-tabel', $rekap)];

        foreach ($rekap['clusters'] as $c) {
            $sheets[] = new LaporanBltSheet(
                'FORMAT BLT DETAIL-'.$c['label'],
                'fo.laporan.blt-detail-tabel',
                LaporanKomisiBlt::bltDetail((int) $c['id'], $this->from, $this->to) + ['judul' => $c['label']],
            );
        }

        return $sheets;
    }
}
