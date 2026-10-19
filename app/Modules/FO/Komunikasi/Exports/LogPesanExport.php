<?php

namespace App\Modules\FO\Komunikasi\Exports;

use App\Modules\FO\Komunikasi\Support\JenisPesan;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

/** Monitoring Pesan → Excel (baris sesuai filter yang sedang aktif). */
class LogPesanExport implements FromArray, ShouldAutoSize, WithHeadings
{
    public function __construct(private readonly Collection $rows) {}

    public function headings(): array
    {
        return ['Waktu', 'Kanal', 'Jenis', 'Status', 'Penerima', 'Tujuan', 'Dokumen', 'Subjek', 'Alasan', 'Percobaan'];
    }

    public function array(): array
    {
        return $this->rows->map(fn ($r) => [
            optional($r->created_at)->format('Y-m-d H:i:s'),
            $r->channel,
            JenisPesan::label($r->jenis),
            $r->status,
            $r->penerima_nama,
            $r->tujuan,
            $r->ref_no,
            $r->subjek,
            $r->alasan,
            $r->percobaan,
        ])->all();
    }
}
