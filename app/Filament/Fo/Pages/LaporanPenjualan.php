<?php

namespace App\Filament\Fo\Pages;

use App\Filament\Concerns\MematuhiIzinHalaman;
use App\Modules\FO\MarketingSales\Controllers\LaporanPenjualanController;
use App\Modules\FO\MarketingSales\Models\Lokasi;
use Filament\Pages\Page;

/**
 * Laporan Penjualan (Penjualan Netto) — filter Cluster + Periode, preview di layar,
 * export Excel format legacy Java Core (route fo.laporan.penjualan.excel).
 */
class LaporanPenjualan extends Page
{
    use MematuhiIzinHalaman;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationGroup = 'Marketing & Sales';

    protected static ?string $navigationLabel = 'Laporan Penjualan';

    protected static ?string $title = 'Laporan Penjualan';

    protected static ?int $navigationSort = 10;

    protected static string $view = 'filament.fo.pages.laporan-penjualan';

    /** Batas baris preview di layar (export Excel selalu penuh). */
    public const PREVIEW_LIMIT = 400;

    public ?string $lokasiId = null; // kosong = All Cluster

    public ?string $from = null;

    public ?string $to = null;

    /** Preview baru dirender setelah user klik tombol Preview. */
    public bool $ready = false;

    public function mount(): void
    {
        $this->from = now()->startOfMonth()->toDateString();
        $this->to = now()->toDateString();
    }

    /** @return array<int|string,string> */
    public function getLokasiOptions(): array
    {
        return Lokasi::query()->orderBy('nama')->pluck('nama', 'id')->all();
    }

    public function preview(): void
    {
        $this->ready = true;
    }

    /**
     * Data preview (baris dibatasi PREVIEW_LIMIT; total & count tetap dari seluruh data).
     *
     * @return array{rows: \Illuminate\Support\Collection<int,array<string,mixed>>, total: array<string,float>, count: int}
     */
    public function getReport(): array
    {
        if (! $this->ready) {
            return ['rows' => collect(), 'total' => [], 'count' => 0];
        }

        $d = LaporanPenjualanController::data(
            $this->lokasiId ? (int) $this->lokasiId : null,
            $this->from ?: null,
            $this->to ?: null,
        );

        return [
            'rows'  => $d['rows']->take(self::PREVIEW_LIMIT),
            'total' => $d['total'],
            'count' => $d['rows']->count(),
        ];
    }

    public function getExportUrl(): string
    {
        return route('fo.laporan.penjualan.excel', array_filter([
            'lokasi_id' => $this->lokasiId ?: null,
            'from'      => $this->from ?: null,
            'to'        => $this->to ?: null,
        ]));
    }

    public function getClusterLabel(): string
    {
        return $this->lokasiId ? (string) (Lokasi::find((int) $this->lokasiId)?->nama ?? '-') : 'All Cluster';
    }
}
