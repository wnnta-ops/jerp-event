<?php

namespace App\Modules\FO\Shared\Assistant\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Ekspor Excel dari hasil pencarian Asisten AI (kolom + baris apa adanya).
 */
class CustomerSearchExport implements FromArray, ShouldAutoSize, WithHeadings, WithTitle
{
    /**
     * @param  array<int,string>  $kolom
     * @param  array<int,array<string,mixed>>  $baris
     */
    public function __construct(
        private readonly array $kolom,
        private readonly array $baris,
    ) {}

    public function headings(): array
    {
        return $this->kolom;
    }

    public function array(): array
    {
        // Susun tiap baris mengikuti urutan kolom (nilai saja).
        return array_map(function (array $row) {
            $out = [];
            foreach ($this->kolom as $k) {
                $out[] = $row[$k] ?? null;
            }

            return $out;
        }, $this->baris);
    }

    public function title(): string
    {
        return 'Hasil Pencarian';
    }
}
