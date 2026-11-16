<?php

namespace App\Modules\Event\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** Satu sheet sederhana: judul, baris judul kolom (tebal), lalu data. */
class EventSheet implements FromArray, ShouldAutoSize, WithHeadings, WithStyles, WithTitle
{
    /**
     * @param  list<string>  $judulKolom
     * @param  list<list<mixed>>  $baris
     */
    public function __construct(
        private readonly string $judul,
        private readonly array $judulKolom,
        private readonly array $baris,
    ) {}

    public function array(): array
    {
        return $this->baris;
    }

    public function headings(): array
    {
        return $this->judulKolom;
    }

    public function title(): string
    {
        return mb_substr($this->judul, 0, 31);
    }

    /** @return array<int, array<string, mixed>> */
    public function styles(Worksheet $sheet): array
    {
        $sheet->freezePane('A2');

        return [1 => ['font' => ['bold' => true]]];
    }
}
