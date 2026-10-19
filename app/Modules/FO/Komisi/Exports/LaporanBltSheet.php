<?php

namespace App\Modules\FO\Komisi\Exports;

use Illuminate\Contracts\View\View;
use Maatwebsite\Excel\Concerns\FromView;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithTitle;

/** Satu sheet dari view tabel laporan BLT. */
class LaporanBltSheet implements FromView, ShouldAutoSize, WithTitle
{
    public function __construct(private readonly string $judul, private readonly string $view, private readonly array $data) {}

    public function view(): View
    {
        return view($this->view, $this->data + ['excel' => true]);
    }

    public function title(): string
    {
        return mb_substr($this->judul, 0, 31); // batas nama sheet Excel
    }
}
