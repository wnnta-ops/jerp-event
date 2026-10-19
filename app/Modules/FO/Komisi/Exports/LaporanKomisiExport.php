<?php

namespace App\Modules\FO\Komisi\Exports;

use Illuminate\Contracts\View\View;
use Maatwebsite\Excel\Concerns\FromView;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

/** Laporan Komisi → Excel, susunan FORMAT KOMISI. */
class LaporanKomisiExport extends DefaultValueBinder implements FromView, ShouldAutoSize, WithCustomValueBinder, WithTitle
{
    public function __construct(private readonly array $data) {}

    public function view(): View
    {
        return view('fo.laporan.komisi-tabel', $this->data + ['excel' => true]);
    }

    public function title(): string
    {
        return 'FORMAT KOMISI';
    }

    /** Kolom V (Nomor Rekening) tetap teks: nol di depan nomor rekening tidak boleh hilang. */
    public function bindValue(Cell $cell, $value): bool
    {
        if ($cell->getColumn() === 'V' && $value !== null && $value !== '') {
            $cell->setValueExplicit((string) $value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }
}
