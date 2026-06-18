<?php

namespace App\Filament\Fo\Pages;

use App\Modules\FO\FinanceAR\Models\Bg;
use App\Modules\FO\FinanceAR\Models\Bkm;
use App\Modules\FO\FinanceAR\Models\Refund;
use App\Modules\FO\FinanceAR\Models\TransferAnonim;
use App\Modules\FO\FinanceAR\Models\Tts;
use App\Modules\FO\MarketingSales\Models\KontrakTagihan;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

/** Dashboard Finance AR — ringkasan piutang, collection, & tindak lanjut. */
class ReminderAr extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-presentation-chart-line';

    protected static ?string $navigationGroup = 'Finance AR';

    protected static ?string $navigationLabel = 'Dashboard AR';

    protected static ?string $title = 'Dashboard Finance AR';

    protected static ?int $navigationSort = 70;

    protected static string $view = 'filament.fo.pages.reminder-ar';

    /** Metrik utama (hero). @return array<int,array<string,mixed>> */
    public function getMetrics(): array
    {
        $outstanding = (float) KontrakTagihan::whereColumn('pelunasan', '<', 'nilai')->sum(DB::raw('nilai - pelunasan'));
        $jatuhTempo = (float) KontrakTagihan::whereColumn('pelunasan', '<', 'nilai')
            ->whereYear('jatuh_tempo', now()->year)->whereMonth('jatuh_tempo', now()->month)->sum(DB::raw('nilai - pelunasan'));
        $collection = (float) Bkm::whereYear('tgl_bkm', now()->year)->whereMonth('tgl_bkm', now()->month)->sum('amount');
        $ttsPending = Tts::whereIn('status', ['DRAFT', 'KONFIRMASI'])->count();

        return [
            ['label' => 'Total Piutang (AR)', 'value' => $this->rp($outstanding), 'icon' => 'heroicon-o-banknotes',
                'grad' => 'linear-gradient(135deg,#6366f1,#4338ca)', 'sub' => 'Sisa tagihan belum lunas'],
            ['label' => 'Jatuh Tempo Bulan Ini', 'value' => $this->rp($jatuhTempo), 'icon' => 'heroicon-o-calendar-days',
                'grad' => 'linear-gradient(135deg,#f59e0b,#b45309)', 'sub' => now()->isoFormat('MMMM Y')],
            ['label' => 'Collection Bulan Ini', 'value' => $this->rp($collection), 'icon' => 'heroicon-o-arrow-trending-up',
                'grad' => 'linear-gradient(135deg,#10b981,#047857)', 'sub' => 'Total BKM (kas masuk)'],
            ['label' => 'TTS Belum Posting', 'value' => number_format($ttsPending), 'icon' => 'heroicon-o-ticket',
                'grad' => 'linear-gradient(135deg,#ef4444,#b91c1c)', 'sub' => 'Perlu Buka Kuitansi'],
        ];
    }

    /** Collection (BKM) 6 bulan terakhir. @return array<int,array{label:string,value:float}> */
    public function getCollection(): array
    {
        $out = [];
        for ($i = 5; $i >= 0; $i--) {
            $m = now()->subMonths($i);
            $out[] = [
                'label' => $m->isoFormat('MMM'),
                'value' => (float) Bkm::whereYear('tgl_bkm', $m->year)->whereMonth('tgl_bkm', $m->month)->sum('amount'),
            ];
        }

        return $out;
    }

    /** Kartu tindak lanjut. @return array<int,array<string,mixed>> */
    public function getTindakLanjut(): array
    {
        $today = now()->toDateString();

        return [
            ['label' => 'TTS Belum Posting', 'icon' => 'heroicon-o-ticket', 'color' => '#f59e0b',
                'count' => Tts::whereIn('status', ['DRAFT', 'KONFIRMASI'])->count(), 'url' => url('/fo/tts')],
            ['label' => 'Giro Jatuh Tempo', 'icon' => 'heroicon-o-clock', 'color' => '#0ea5e9',
                'count' => Bg::whereIn('status', ['NORMAL', 'JATUH_TEMPO'])->whereNotNull('tgl_jatuh_tempo')->whereDate('tgl_jatuh_tempo', '<=', $today)->count(), 'url' => url('/fo/bgs')],
            ['label' => 'Giro Bermasalah', 'icon' => 'heroicon-o-exclamation-triangle', 'color' => '#ef4444',
                'count' => Bg::where('status', 'BERMASALAH')->count(), 'url' => url('/fo/bgs')],
            ['label' => 'Transfer Anonim Baru', 'icon' => 'heroicon-o-question-mark-circle', 'color' => '#8b5cf6',
                'count' => TransferAnonim::where('status', 'BARU')->count(), 'url' => url('/fo/transfer-anonims')],
            ['label' => 'Refund Diproses', 'icon' => 'heroicon-o-arrow-uturn-left', 'color' => '#10b981',
                'count' => Refund::whereIn('status', ['DRAFT', 'DIPROSES'])->count(), 'url' => url('/fo/refunds')],
        ];
    }

    /** Komposisi instrumen AR (jumlah dokumen). @return array<int,array<string,mixed>> */
    public function getInstrumen(): array
    {
        return [
            ['label' => 'TTS', 'count' => Tts::count(), 'color' => '#10b981', 'url' => url('/fo/tts')],
            ['label' => 'Kwitansi (BKM)', 'count' => Bkm::count(), 'color' => '#06b6d4', 'url' => url('/fo/bkms')],
            ['label' => 'Bilyet Giro', 'count' => Bg::count(), 'color' => '#f97316', 'url' => url('/fo/bgs')],
            ['label' => 'Refund', 'count' => Refund::count(), 'color' => '#14b8a6', 'url' => url('/fo/refunds')],
        ];
    }

    private function rp(float $v): string
    {
        if ($v >= 1_000_000_000) {
            return 'Rp '.number_format($v / 1_000_000_000, 2, ',', '.').' M';
        }
        if ($v >= 1_000_000) {
            return 'Rp '.number_format($v / 1_000_000, 1, ',', '.').' Jt';
        }

        return 'Rp '.number_format($v, 0, ',', '.');
    }
}
