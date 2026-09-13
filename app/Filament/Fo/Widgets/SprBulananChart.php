<?php

namespace App\Filament\Fo\Widgets;

use App\Modules\FO\MarketingSales\Services\SprBulananService;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

/**
 * Sales Report — SPR per Bulan. SATU panel grafik dengan TIGA tampilan yang dipilih lewat
 * dropdown di pojok kanan atas panel (slot headerEnd milik ChartWidget).
 *
 * SATU BATASAN YANG MEMBENTUK SELURUH RANCANGAN INI: saat dropdown diganti, komponen Alpine
 * bawaan Filament HANYA mengirim ulang `data` — `options` dan `type` dibekukan sejak render
 * pertama (lihat chart.js: handler updateChartData hanya menyetel chart.data lalu update).
 * Karena itu ketiga tampilan berbagi SATU objek options dan SATU tipe dasar ('bar'); yang
 * membedakan hanya isi datasets, termasuk `type: 'line'` per-dataset untuk garis target.
 * Apa pun yang perlu berbeda antar tampilan harus dihitung saat menggambar dari chart.data,
 * bukan dikirim dari PHP.
 */
class SprBulananChart extends ChartWidget
{
    protected static ?string $maxHeight = '320px';

    protected int | string | array $columnSpan = 'full';

    public ?string $filter = 'target';

    /** Warna deret — dipilih agar tetap terbaca di mode terang maupun gelap. */
    private const WARNA = [
        'realisasi' => '#ff8c00',   // jingga merek
        'lalu'      => '#94a3b8',   // abu kebiruan
        'gross'     => '#f59e0b',
        'nett'      => '#22c55e',
        'batal'     => '#ef4444',
    ];

    /** Warna garis target, dipakai berputar mengikuti banyaknya skenario yang diimpor. */
    private const WARNA_TARGET = ['#94a3b8', '#38bdf8', '#22c55e', '#a855f7', '#f43f5e'];

    private function svc(): SprBulananService
    {
        return app(SprBulananService::class);
    }

    private function tahun(): int
    {
        return (int) now()->year;
    }

    protected function getFilters(): ?array
    {
        $t = $this->tahun();

        return [
            'target'  => 'Target vs Realisasi '.$t,
            'banding' => 'Realisasi '.$t.' vs '.($t - 1),
            'detail'  => 'Detail SPR '.$t.' (Gross / Nett / Batal)',
        ];
    }

    public function getHeading(): string | Htmlable | null
    {
        return '📊 Sales Report '.$this->tahun().' — SPR per Bulan';
    }

    public function getDescription(): string | Htmlable | null
    {
        $svc    = $this->svc();
        $tahun  = $this->tahun();
        $nett   = array_sum($svc->perBulan($tahun)['nett']);
        $target = $svc->target($tahun);
        $utama  = $target[0] ?? null;

        $bagian = [];
        if ($utama !== null && $utama['setahun'] > 0) {
            $bagian[] = 'Target tahunan <b>'.number_format($utama['setahun'], 0, ',', '.').' unit</b> ('.e($utama['skenario']).')';
            $bagian[] = 'realisasi YTD <b>'.number_format($nett, 0, ',', '.').' unit</b> ('.round($nett / $utama['setahun'] * 100).'%)';
        } else {
            $bagian[] = 'Realisasi YTD <b>'.number_format($nett, 0, ',', '.').' unit</b>';
            // Tanpa kalimat ini, tampilan "Target vs Realisasi" tampak rusak — batangnya ada,
            // garisnya tidak — dan tidak ada apa pun yang menjelaskan kenapa.
            $bagian[] = '<b>target belum diimpor</b> (Setup Sales → Upload Target SPR)';
        }
        $bagian[] = 'SPR = kontrak bertahap <b>SPR</b>, Nett = Gross − Batal';
        $bagian[] = 'klik legenda untuk menyembunyikan/menampilkan seri';

        return new HtmlString(implode(' · ', $bagian).'.');
    }

    protected function getType(): string
    {
        return 'bar';
    }

    /** @return array<string,mixed> */
    protected function getData(): array
    {
        $svc    = $this->svc();
        $tahun  = $this->tahun();
        $sampai = $svc->bulanBerjalan($tahun);
        $kini   = $svc->perBulan($tahun);

        $datasets = match ($this->filter) {
            'banding' => $this->deretBanding($svc, $tahun, $sampai, $kini),
            'detail'  => $this->deretDetail($svc, $sampai, $kini),
            default   => $this->deretTarget($svc, $tahun, $sampai, $kini),
        };

        return ['labels' => SprBulananService::BULAN, 'datasets' => $datasets];
    }

    /** @return array<int,array<string,mixed>> */
    private function deretTarget(SprBulananService $svc, int $tahun, int $sampai, array $kini): array
    {
        $out = [$this->batang('Realisasi (Nett SPR)', $svc->hinggaBulan($kini['nett'], $sampai), self::WARNA['realisasi'])];

        foreach ($svc->target($tahun) as $i => $t) {
            $warna = self::WARNA_TARGET[$i % count(self::WARNA_TARGET)];
            $out[] = [
                'type' => 'line',
                'label' => $this->judul($t['skenario'], $t['setahun']),
                // Garis target memuat DUA BELAS bulan penuh walau realisasinya baru sampai bulan
                // berjalan: target adalah rencana setahun, dan memotongnya di bulan berjalan
                // menghilangkan justru bagian yang dipakai orang untuk memperkirakan sisa tahun.
                'data' => array_values($t['bulan']),
                'borderColor' => $warna,
                'backgroundColor' => $warna,
                'borderWidth' => 2,
                // Skenario pertama = target induk (garis utuh); revisi digambar putus-putus
                // supaya tidak tertukar dengan target yang disahkan.
                'borderDash' => $i === 0 ? [] : [6, 4],
                'pointRadius' => 2.5,
                'pointHoverRadius' => 4,
                'tension' => 0.35,
                'fill' => false,
            ];
        }

        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    private function deretBanding(SprBulananService $svc, int $tahun, int $sampai, array $kini): array
    {
        $lalu = $svc->perBulan($tahun - 1);

        return [
            $this->batang('Nett SPR '.$tahun, $svc->hinggaBulan($kini['nett'], $sampai), self::WARNA['realisasi']),
            // Tahun lalu SELALU dua belas bulan penuh — tahunnya memang sudah habis.
            $this->batang('Nett SPR '.($tahun - 1), $svc->hinggaBulan($lalu['nett'], 12), self::WARNA['lalu']),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function deretDetail(SprBulananService $svc, int $sampai, array $kini): array
    {
        return [
            $this->batang('SPR (Gross)', $svc->hinggaBulan($kini['gross'], $sampai), self::WARNA['gross']),
            $this->batang('Nett SPR', $svc->hinggaBulan($kini['nett'], $sampai), self::WARNA['nett']),
            $this->batang('Batal', $svc->hinggaBulan($kini['batal'], $sampai), self::WARNA['batal']),
        ];
    }

    /**
     * @param  array<int,int|null>  $bulan
     * @return array<string,mixed>
     */
    private function batang(string $label, array $bulan, string $warna): array
    {
        return [
            'type' => 'bar',
            'label' => $this->judul($label, (int) array_sum(array_map(fn ($v) => (int) $v, $bulan))),
            'data' => array_values($bulan),
            'backgroundColor' => $warna,
            'borderColor' => $warna,
            'borderRadius' => 3,
            'maxBarThickness' => 42,
        ];
    }

    /**
     * Jumlah setahun ditempelkan ke nama deret di legenda. Lampiran aslinya memakai kotak
     * "TOTAL SETAHUN" terpisah; ditempelkan ke legenda, angkanya tetap terbaca sekilas tanpa
     * elemen tambahan — dan ikut hilang saat serinya disembunyikan, yang justru benar.
     */
    private function judul(string $label, int $setahun): string
    {
        return $label.'  ·  Σ '.number_format($setahun, 0, ',', '.');
    }

    protected function getOptions(): RawJs
    {
        // RawJs, bukan array: Illuminate\Support\Js::from() memulangkan JavaScript mentah apa
        // adanya untuk objek Js, sehingga callback tooltip bisa ikut. Tanpa itu, semua opsi
        // harus berupa data murni dan tidak ada cara menulis satuan "unit" di tooltip.
        return RawJs::make(<<<'JS'
            {
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                // Ruang di atas grafik untuk label nilai; tanpa ini angka di atas batang
                // tertinggi terpotong tepi kanvas.
                layout: { padding: { top: 24 } },
                plugins: {
                    labelNilai: { aktif: true },
                    legend: {
                        position: 'top',
                        labels: { usePointStyle: true, pointStyle: 'rectRounded', boxWidth: 10, padding: 14, font: { size: 11 } },
                    },
                    tooltip: {
                        callbacks: {
                            label: function (c) {
                                var nama = String(c.dataset.label || '').split('  ·  ')[0];
                                var v = c.parsed.y;
                                return nama + ': ' + (v === null || v === undefined ? '—' : v + ' unit');
                            },
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
