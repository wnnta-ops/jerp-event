<?php

namespace App\Modules\FO\MarketingSales\Exports;

use App\Modules\FO\MarketingSales\Models\SprTarget;
use App\Modules\FO\MarketingSales\Services\SprBulananService;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Template Excel Upload Target SPR.
 *
 * Bila target tahun berjalan SUDAH ada, templatenya berisi angka yang sekarang tersimpan —
 * jadi berkas ini sekaligus menjadi cara mengunduh, menyunting, lalu mengunggahnya kembali.
 * Template kosong yang memaksa mengetik ulang 12 angka × beberapa skenario adalah cara
 * paling mudah melahirkan salah ketik.
 */
class SprTargetTemplateExport implements FromArray, ShouldAutoSize, WithHeadings, WithTitle
{
    public function __construct(
        private readonly int $tenantId = 1,
        private readonly ?int $tahun = null,
    ) {}

    /** @return array<int,string> */
    public function headings(): array
    {
        return array_merge(['Tahun', 'Skenario'], SprBulananService::BULAN);
    }

    /** @return array<int,array<int,string|int>> */
    public function array(): array
    {
        $tahun = $this->tahun ?? (int) now()->year;

        $tersimpan = SprTarget::query()->withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->where('tahun', $tahun)
            ->orderBy('urutan')->orderBy('skenario')
            ->get();

        if ($tersimpan->isNotEmpty()) {
            return $tersimpan->map(fn (SprTarget $t) => array_merge(
                [$t->tahun, $t->skenario],
                array_values($t->perBulan()),
            ))->all();
        }

        // Belum ada apa-apa: dua baris contoh yang memperlihatkan bentuknya — satu target
        // induk dan satu revisi — supaya operator langsung tahu revisi ditambah sebagai
        // BARIS baru, bukan sebagai kolom baru.
        return [
            array_merge([$tahun, 'Target (WPnB)'], array_fill(0, 12, 0)),
            array_merge([$tahun, 'Adjust 1'], array_fill(0, 12, 0)),
        ];
    }

    public function title(): string
    {
        return 'TARGET SPR';
    }
}
