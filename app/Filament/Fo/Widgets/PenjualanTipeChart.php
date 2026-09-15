<?php

namespace App\Filament\Fo\Widgets;

use App\Filament\Fo\Resources\SprResource;
use App\Filament\Fo\Widgets\Concerns\FilterPeriode;
use App\Modules\FO\MarketingSales\Services\SprBulananService;
use App\Support\Akses;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

/**
 * Trend Penjualan per Tipe Rumah — satu bulan, satu batang per tipe, diurut dari terbanyak.
 *
 * Angkanya datang dari SprBulananService::perTipe() yang memakai definisi SPR yang SAMA dengan
 * grafik "Sales Report — SPR per Bulan" di dashboard ini, sehingga jumlah seluruh batang di
 * sini persis sama dengan batang Gross bulan itu di panel sebelahnya. Dua panel bertetangga
 * yang angkanya tidak bisa dijumlahkan satu sama lain adalah cara tercepat membuat orang
 * berhenti memercayai keduanya.
 */
class PenjualanTipeChart extends ChartWidget
{
    use FilterPeriode;

    /**
     * Dasbor terbuka untuk semua yang bisa masuk panel, jadi panel ini harus dijaga sendiri —
     * izinnya disamakan dengan resource SPR supaya satu centang mengatur keduanya.
     */
    public static function canView(): bool
    {
        return Akses::boleh(Akses::izinUntuk(SprResource::class, 'viewAny'));
    }

    protected static ?string $maxHeight = '300px';

    protected int | string | array $columnSpan = 'full';

    public ?string $filter = null;

    /** Jingga merek untuk yang terbesar, memudar ke abu untuk yang kecil. */
    private const WARNA = ['#ff8c00', '#f59e0b', '#fbbf24', '#fcd9a8', '#94a3b8'];

    private function svc(): SprBulananService
    {
        return app(SprBulananService::class);
    }

    protected function bulanPertama(): ?Carbon
    {
        return $this->svc()->bulanPertama();
    }

    public function getHeading(): string | Htmlable | null
    {
        return 'Trend Penjualan per Tipe Rumah — '.$this->judulPeriode().' (Unit)';
    }

    public function getDescription(): string | Htmlable | null
    {
        $p     = $this->periode();
        $baris = $this->svc()->perTipe((int) $p->year, (int) $p->month);
        $total = array_sum(array_column($baris, 'unit'));

        if ($baris === []) {
            return new HtmlString('Belum ada SPR pada <b>'.$this->judulPeriode().'</b>. Ganti periodenya lewat pilihan di pojok kanan atas.');
        }

        $rinci = implode(', ', array_map(fn ($b) => e($b['tipe']).' ('.$b['unit'].')', $baris));

        return new HtmlString(
            $this->judulPeriode().': <b>'.$total.' unit</b> — '.$rinci.'. '
            .'Dihitung dari kontrak bertahap <b>SPR</b> menurut tanggal kontraknya, '
            .'<b>tanpa mengurangi yang batal</b> — jumlah seluruh batang di sini sama dengan '
            .'batang <b>Gross</b> bulan itu pada panel Sales Report.'
        );
    }

    protected function getType(): string
    {
        return 'bar';
    }

    /** @return array<string,mixed> */
    protected function getData(): array
    {
        $p     = $this->periode();
        $baris = $this->svc()->perTipe((int) $p->year, (int) $p->month);

        return [
            'labels' => array_column($baris, 'tipe'),
            'datasets' => [[
                'label' => 'Unit terjual',
                'data' => array_column($baris, 'unit'),
                // Warna per batang, bukan satu warna untuk semua: urutannya sudah menurun,
                // jadi gradasinya ikut memperkuat mana yang besar tanpa menambah keterangan.
                'backgroundColor' => array_map(
                    fn ($i) => self::WARNA[min($i, count(self::WARNA) - 1)],
                    array_keys($baris),
                ),
                'borderRadius' => 3,
                'maxBarThickness' => 56,
            ]],
        ];
    }

    protected function getOptions(): RawJs
    {
        return RawJs::make(<<<'JS'
            {
                maintainAspectRatio: false,
                layout: { padding: { top: 24 } },
                plugins: {
                    labelNilai: { aktif: true },
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function (c) { return (c.parsed.y === null ? '—' : c.parsed.y + ' unit'); },
                        },
                    },
                },
                scales: {
                    x: { grid: { display: false }, ticks: { font: { size: 11 } } },
                    y: { beginAtZero: true, ticks: { precision: 0, font: { size: 11 } }, grid: { color: 'rgba(148,163,184,0.18)' } },
                },
            }
        JS);
    }
}
