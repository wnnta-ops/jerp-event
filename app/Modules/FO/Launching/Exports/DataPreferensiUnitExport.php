<?php

namespace App\Modules\FO\Launching\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use PhpOffice\PhpSpreadsheet\Cell\StringValueBinder;

/**
 * Data Preferensi Unit → Excel (baris sesuai filter & pencarian yang aktif).
 * Semua sel teks: nomor telepon tetap utuh (angka 0 di depan tidak hilang).
 */
class DataPreferensiUnitExport extends StringValueBinder implements FromArray, ShouldAutoSize, WithCustomValueBinder, WithHeadings
{
    public function __construct(private readonly Collection $rows) {}

    public function headings(): array
    {
        return ['Nama Customer', 'Telepon', 'Preferensi Unit', 'Tgl Daftar', 'Project'];
    }

    public function array(): array
    {
        // Rumus sama dengan kolom tabel (cadangan telepon dari customer, pilihan_budget lama).
        return $this->rows->map(fn ($r) => [
            $r->nama_pendaftar,
            $r->phone ?: ($r->customer?->mobile ?: $r->customer?->phone),
            $r->preferensi_unit_nama ?: (filled($r->pilihan_budget) ? implode(', ', (array) $r->pilihan_budget) : null),
            optional($r->tgl_daftar)->format('d/m/Y H:i'),
            $r->projectPref?->name,
        ])->all();
    }
}
