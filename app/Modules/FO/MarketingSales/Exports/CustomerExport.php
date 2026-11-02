<?php

namespace App\Modules\FO\MarketingSales\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

/** Master Customer → Excel (baris sesuai filter & pencarian yang sedang aktif). */
class CustomerExport extends DefaultValueBinder implements FromArray, ShouldAutoSize, WithCustomValueBinder, WithHeadings
{
    /** Kolom angka murni (Penghasilan). Kolom lain ditulis sebagai teks supaya NIK/HP/rekening tak jadi 1,23E+15. */
    private const KOLOM_ANGKA = ['T'];

    public function __construct(private readonly Collection $rows) {}

    public function headings(): array
    {
        return [
            'No. Customer', 'Nama', 'NIK', 'NPWP', 'Tempat Lahir', 'Tanggal Lahir', 'Agama',
            'No. Telp', 'No. HP', 'Email',
            'Jalan', 'RT/RW', 'Kelurahan', 'Kecamatan', 'Kota', 'Provinsi', 'Kode Pos',
            'Pekerjaan', 'Perusahaan', 'Penghasilan / Bulan',
            'Bank', 'No. Rekening', 'Atas Nama',
            'Project', 'Lead Status', 'Status Aktif', 'Tanggal Daftar',
        ];
    }

    public function array(): array
    {
        return $this->rows->map(fn ($c) => [
            $c->code,
            $c->full_name,
            $c->nik,
            $c->npwp,
            $c->birth_place,
            optional($c->birth_date)->format('Y-m-d'),
            $c->religion,
            $c->phone,
            $c->mobile,
            $c->email,
            $c->jalan ?: $c->address,
            $c->rt_rw,
            $c->kelurahan,
            $c->kecamatan,
            $c->city,
            $c->province,
            $c->postal_code,
            $c->occupation,
            $c->company_name,
            $c->income_monthly !== null ? (float) $c->income_monthly : null,
            $c->bank?->name,
            $c->no_rekening,
            $c->atas_nama_rekening,
            $c->project?->name,
            $c->lead_status,
            $c->is_active ? 'Aktif' : 'Nonaktif',
            optional($c->created_at)->format('Y-m-d H:i'),
        ])->all();
    }

    public function bindValue(Cell $cell, $value): bool
    {
        if (is_string($value) && ! in_array($cell->getColumn(), self::KOLOM_ANGKA, true)) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }
}
