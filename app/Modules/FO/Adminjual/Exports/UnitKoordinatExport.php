<?php

namespace App\Modules\FO\Adminjual\Exports;

use App\Modules\FO\MarketingSales\Models\Lokasi;
use App\Modules\FO\MarketingSales\Models\Unit;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export koordinat polygon unit per Cluster (Lokasi) — kebalikan KoordinatImport
 * (round-trip: export → edit Excel → Upload Koordinat lagi).
 *
 * Kolom: No. Unit (blok-no_unit), Peta (nama cluster), Koordinat ("x1,y1,x2,y2,...").
 * Format sel: No. Unit = TEXT, Peta = General, Koordinat = NUMBER (sesuai template).
 * Unit tanpa polygon → kolom Koordinat kosong.
 */
class UnitKoordinatExport implements FromCollection, ShouldAutoSize, WithColumnFormatting, WithHeadings, WithStyles
{
    public function __construct(private readonly int $lokasiId) {}

    public function headings(): array
    {
        return ['No. Unit', 'Peta', 'Koordinat'];
    }

    public function collection(): Collection
    {
        $peta = Lokasi::find($this->lokasiId)?->nama ?? '';

        return Unit::where('lokasi_id', $this->lokasiId)
            ->get(['blok', 'no_unit', 'siteplan_coords'])
            ->map(fn (Unit $u) => [
                'kode'  => trim(($u->blok ?? '').'-'.($u->no_unit ?? ''), '-'),
                'peta'  => $peta,
                'koord' => $this->flatten($u->siteplan_coords),
            ])
            // Urut natural (I-1, I-2, ..., I-10, I-11, I-11A, ..., II-1) — cocok dgn tampilan/template.
            ->sort(fn ($a, $b) => strnatcasecmp($a['kode'], $b['kode']))
            ->map(fn ($r) => [$r['kode'], $r['peta'], $r['koord']])
            ->values();
    }

    /** [[x,y],...] → "x1,y1,x2,y2,..."; null/invalid → "". */
    private function flatten(mixed $coords): string
    {
        if (! is_array($coords)) {
            return '';
        }
        $flat = [];
        foreach ($coords as $pair) {
            if (is_array($pair) && count($pair) === 2) {
                $flat[] = (int) round((float) $pair[0]);
                $flat[] = (int) round((float) $pair[1]);
            }
        }

        return implode(',', $flat);
    }

    public function columnFormats(): array
    {
        return [
            'A' => NumberFormat::FORMAT_TEXT,   // No. Unit → Text (jaga kode spt "JRK2-002-I-1")
            'C' => NumberFormat::FORMAT_NUMBER, // Koordinat → Number (kolom B "Peta" biarkan General)
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true]]]; // header tebal
    }
}
