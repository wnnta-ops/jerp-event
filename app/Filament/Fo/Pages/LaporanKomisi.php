<?php

namespace App\Filament\Fo\Pages;

use App\Filament\Concerns\MematuhiIzinHalaman;
use App\Modules\FO\Komisi\Services\LaporanKomisiBlt;
use App\Modules\FO\MarketingSales\Models\Lokasi;
use Filament\Pages\Page;

/** Komisi → Laporan Komisi (format Excel Finance "FORMAT KOMISI"). */
class LaporanKomisi extends Page
{
    use MematuhiIzinHalaman;

    protected static ?string $slug = 'laporan-komisi';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Laporan Komisi';

    protected static string $view = 'filament.fo.pages.laporan-komisi';

    public const PREVIEW_LIMIT = 400;

    public ?string $from = null;

    public ?string $to = null;

    public ?string $lokasiId = null;

    public ?string $status = null;

    public string $tanggal = 'pengajuan';

    public bool $ready = false;

    public function mount(): void
    {
        $this->from = now()->startOfMonth()->toDateString();
        $this->to = now()->toDateString();
    }

    public function preview(): void
    {
        $this->ready = true;
    }

    /** @return array<int|string,string> */
    public function getLokasiOptions(): array
    {
        return Lokasi::query()->whereHas('units')->orderBy('kode')->pluck('nama', 'id')->all();
    }

    /** @return array{rows: \Illuminate\Support\Collection, total: array, count: int} */
    public function getReport(): array
    {
        if (! $this->ready) {
            return ['rows' => collect(), 'total' => [], 'count' => 0];
        }
        $d = LaporanKomisiBlt::komisi($this->from ?: null, $this->to ?: null,
            $this->lokasiId ? (int) $this->lokasiId : null, $this->status ?: null, $this->tanggal);

        return ['rows' => $d['rows']->take(self::PREVIEW_LIMIT), 'total' => $d['total'], 'count' => $d['rows']->count()];
    }

    public function getExportUrl(): string
    {
        return route('fo.laporan.komisi.excel', array_filter([
            'from' => $this->from ?: null, 'to' => $this->to ?: null, 'lokasi_id' => $this->lokasiId ?: null,
            'status' => $this->status ?: null, 'tanggal' => $this->tanggal,
        ]));
    }
}
