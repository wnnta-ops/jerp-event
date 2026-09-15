<?php

namespace App\Filament\Fo\Widgets;

use App\Filament\Fo\Resources\NupRegistrationResource;
use App\Filament\Fo\Widgets\Concerns\FilterPeriode;
use App\Modules\FO\Launching\Services\UtjAgensiService;
use App\Support\Akses;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

/**
 * Top Agent (UTJ) — satu bulan, satu batang MENDATAR per agensi, diurut dari terbanyak.
 *
 * Batangnya mendatar karena nama agensi panjang: dipasang tegak, namanya akan diputar miring
 * atau dipotong. Sumbu dibalik lewat indexAxis 'y' di options — aman karena options dibekukan
 * sejak render pertama dan tidak pernah perlu berubah antar periode.
 *
 * Yang dihitung BANYAKNYA UTJ, bukan rupiahnya. Aturan "UTJ masuk" ada seluruhnya di
 * UtjAgensiService, termasuk keputusan untuk ikut menghitung yang disetujui manual dan untuk
 * tidak menghitung yang sudah dikembalikan.
 */
class TopAgensiUtjChart extends ChartWidget
{
    use FilterPeriode;

    /** Panel ini memampangkan data NUP di halaman depan — izinnya disamakan dengan resource NUP. */
    public static function canView(): bool
    {
        return Akses::boleh(Akses::izinUntuk(NupRegistrationResource::class, 'viewAny'));
    }

    protected static ?string $maxHeight = '300px';

    protected int | string | array $columnSpan = 'full';

    public ?string $filter = null;

    private const WARNA = '#ff8c00';

    private function svc(): UtjAgensiService
    {
        return app(UtjAgensiService::class);
    }

    protected function bulanPertama(): ?Carbon
    {
        return $this->svc()->bulanPertama();
    }

    public function getHeading(): string | Htmlable | null
    {
        return 'Top Agent — '.$this->judulPeriode().' (UTJ)';
    }

    public function getDescription(): string | Htmlable | null
    {
        $p     = $this->periode();
        $baris = $this->svc()->perAgensi((int) $p->year, (int) $p->month);

        if ($baris === []) {
            return new HtmlString('Belum ada UTJ masuk pada <b>'.$this->judulPeriode().'</b>. Ganti periodenya lewat pilihan di pojok kanan atas.');
        }

        $teratas = array_slice($baris, 0, 4);
        $rinci   = implode(' · ', array_map(fn ($b) => '<b>'.e($b['agensi']).'</b> ('.$b['utj'].')', $teratas));

        return new HtmlString(
            $this->judulPeriode().': '.$rinci.'. '
            .'Dihitung dari <b>banyaknya UTJ</b> yang masuk pada bulan itu (bukan rupiahnya), '
            .'dikelompokkan menurut <b>agensi</b> pada master agen. '
            // Kedua kalimat berikut yang mencegah angkanya dikira salah saat dibandingkan
            // dengan Report NUP — keduanya memang sengaja berbeda.
            .'Yang disetujui <b>manual</b> ikut dihitung memakai tanggal verifikasinya, '
            .'dan yang sudah <b>dikembalikan</b> tidak dihitung — jadi angkanya bisa lebih kecil daripada Report NUP.'
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
        $baris = $this->svc()->perAgensi((int) $p->year, (int) $p->month);

        return [
            'labels' => array_column($baris, 'agensi'),
            'datasets' => [[
                'label' => 'UTJ',
                'data' => array_column($baris, 'utj'),
                'backgroundColor' => self::WARNA,
                'borderRadius' => 3,
                'maxBarThickness' => 22,
            ]],
        ];
    }

    protected function getOptions(): RawJs
    {
        return RawJs::make(<<<'JS'
            {
                indexAxis: 'y',
                maintainAspectRatio: false,
                layout: { padding: { right: 28 } },
                plugins: {
                    labelNilai: { aktif: true },
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function (c) { return (c.parsed.x === null ? '—' : c.parsed.x + ' UTJ'); },
                        },
                    },
                },
                scales: {
                    x: { beginAtZero: true, ticks: { precision: 0, font: { size: 11 } }, grid: { color: 'rgba(148,163,184,0.18)' } },
                    y: { grid: { display: false }, ticks: { font: { size: 11 } } },
                },
            }
        JS);
    }
}
