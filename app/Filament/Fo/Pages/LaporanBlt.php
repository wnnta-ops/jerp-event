<?php

namespace App\Filament\Fo\Pages;

use App\Filament\Concerns\MematuhiIzinHalaman;
use App\Modules\FO\Komisi\Services\LaporanKomisiBlt;
use Filament\Pages\Page;

/**
 * Komisi → Laporan BLT (format Excel Finance): tab Rekap (all cluster, per tanggal pencairan)
 * dan Detail per cluster (per unit). Export Excel memuat Rekap + satu sheet per cluster.
 */
class LaporanBlt extends Page
{
    use MematuhiIzinHalaman;

    protected static ?string $slug = 'laporan-blt';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Laporan BLT';

    protected static string $view = 'filament.fo.pages.laporan-blt';

    public ?string $from = null;

    public ?string $to = null;

    /** 'rekap' atau id lokasi (cluster) untuk tab detail. */
    public string $tab = 'rekap';

    public bool $ready = false;

    public function mount(): void
    {
        $this->from = now()->startOfYear()->toDateString();
        $this->to = now()->toDateString();
    }

    public function preview(): void
    {
        $this->ready = true;
    }

    public function getClusters(): \Illuminate\Support\Collection
    {
        return LaporanKomisiBlt::clusters();
    }

    public function getRekap(): array
    {
        return LaporanKomisiBlt::bltRekap($this->from ?: null, $this->to ?: null);
    }

    public function getDetail(): array
    {
        $c = $this->getClusters()->firstWhere('id', (int) $this->tab);

        return LaporanKomisiBlt::bltDetail((int) $this->tab, $this->from ?: null, $this->to ?: null)
            + ['judul' => $c['label'] ?? ''];
    }

    public function getExportUrl(): string
    {
        return route('fo.laporan.blt.excel', array_filter(['from' => $this->from ?: null, 'to' => $this->to ?: null]));
    }
}
