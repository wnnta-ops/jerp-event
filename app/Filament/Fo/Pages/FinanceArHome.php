<?php

namespace App\Filament\Fo\Pages;

use App\Modules\FO\FinanceAR\Models\Bg;
use App\Modules\FO\FinanceAR\Models\Bkm;
use App\Modules\FO\FinanceAR\Models\Fp;
use App\Modules\FO\FinanceAR\Models\Memo;
use App\Modules\FO\FinanceAR\Models\Refund;
use App\Modules\FO\FinanceAR\Models\TransferAnonim;
use App\Modules\FO\FinanceAR\Models\Tts;
use App\Modules\FO\FinanceAR\Models\Va;
use Filament\Pages\Page;

/**
 * Finance AR sub-dashboard — landing page after the user clicks the
 * "Finance AR" tile on the FO main dashboard. Hosts the module's sub-menus
 * (TTS, Kwitansi/BKM, Tambah DP, VA, MEMO), mirroring the Launching Center hub.
 * Full-screen (sidebar hidden via FoPanelProvider).
 *
 * "Tambah DP" sengaja masih placeholder (segera tersedia) — flow dibangun nanti
 * saat ada kebutuhan customer menambah DP.
 */
class FinanceArHome extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $title = 'Finance AR';

    protected static ?string $navigationLabel = 'Finance AR';

    protected static ?string $slug = 'finance-ar';

    protected static string $view = 'filament.fo.pages.module-hub';

    protected static bool $shouldRegisterNavigation = false;

    public function getHubBreadcrumb(): string
    {
        return 'Finance AR';
    }

    public function getHubTitle(): string
    {
        return '💵 Finance AR';
    }

    public function getHubSubtitle(): string
    {
        return 'Tanda terima, kwitansi (BKM), tambah DP, dan instrumen pembayaran.';
    }

    public function getHubGradient(): string
    {
        return 'linear-gradient(90deg, #10b981 0%, #06b6d4 50%, #6366f1 100%)';
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getSubTiles(): array
    {
        return [
            [
                'label' => 'TTS',
                'sub' => 'Tanda Terima Sementara',
                'url' => url('/fo/tts'),
                'icon' => 'M9 12h6m-6 4h6m2 5l-2-1-2 1-2-1-2 1-2-1-2 1V5a2 2 0 012-2h10a2 2 0 012 2v16z',
                'gradient' => 'linear-gradient(135deg, #10b981 0%, #047857 100%)',
                'count' => fn () => Tts::where('status', 'KONFIRMASI')->count(),
                'count_label' => 'siap kuitansi',
                'urgent' => true,
            ],
            [
                'label' => 'Kwitansi',
                'sub' => 'Bukti Kas Masuk (BKM) — cetak',
                'url' => url('/fo/bkms'),
                'icon' => 'M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2h2m2-4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z',
                'gradient' => 'linear-gradient(135deg, #06b6d4 0%, #0284c7 100%)',
                'count' => fn () => Bkm::count(),
                'count_label' => 'kwitansi',
            ],
            [
                'label' => 'VA',
                'sub' => 'Virtual Account per customer',
                'url' => url('/fo/vas'),
                'icon' => 'M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z',
                'gradient' => 'linear-gradient(135deg, #8b5cf6 0%, #6d28d9 100%)',
                'count' => fn () => Va::count(),
                'count_label' => 'VA',
            ],
            [
                'label' => 'MEMO',
                'sub' => 'Memo finansial / cashback',
                'url' => url('/fo/memos'),
                'icon' => 'M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z',
                'gradient' => 'linear-gradient(135deg, #f59e0b 0%, #b45309 100%)',
                'count' => fn () => Memo::count(),
                'count_label' => 'memo',
            ],
            [
                'label' => 'Faktur Pajak',
                'sub' => 'FP — di-link ke kuitansi',
                'url' => url('/fo/fps'),
                'icon' => 'M9 12h6m-6 4h6M9 8h6m4 13H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z',
                'gradient' => 'linear-gradient(135deg, #0ea5e9 0%, #0369a1 100%)',
                'count' => fn () => Fp::where('status', 'BELUM_TERPAKAI')->count(),
                'count_label' => 'belum terpakai',
            ],
            [
                'label' => 'Bilyet Giro',
                'sub' => 'BG/Cek + ganti giro',
                'url' => url('/fo/bgs'),
                'icon' => 'M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z',
                'gradient' => 'linear-gradient(135deg, #f97316 0%, #c2410c 100%)',
                'count' => fn () => Bg::where('status', 'BERMASALAH')->count(),
                'count_label' => 'bermasalah',
                'urgent' => true,
            ],
            [
                'label' => 'Refund / CB',
                'sub' => 'Refund booking & lebih bayar',
                'url' => url('/fo/refunds'),
                'icon' => 'M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6',
                'gradient' => 'linear-gradient(135deg, #14b8a6 0%, #0f766e 100%)',
                'count' => fn () => Refund::whereIn('status', ['DRAFT', 'DIPROSES'])->count(),
                'count_label' => 'diproses',
            ],
            [
                'label' => 'Transfer Anonim',
                'sub' => 'Transfer belum teridentifikasi',
                'url' => url('/fo/transfer-anonims'),
                'icon' => 'M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
                'gradient' => 'linear-gradient(135deg, #8b5cf6 0%, #6d28d9 100%)',
                'count' => fn () => TransferAnonim::where('status', 'BARU')->count(),
                'count_label' => 'baru',
                'urgent' => true,
            ],
            [
                'label' => 'Dashboard AR',
                'sub' => 'Piutang, collection & tindak lanjut',
                'url' => url('/fo/reminder-ar'),
                'icon' => 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z',
                'gradient' => 'linear-gradient(135deg, #6366f1 0%, #4338ca 100%)',
                'count' => fn () => Tts::whereIn('status', ['DRAFT', 'KONFIRMASI'])->count(),
                'count_label' => 'perlu tindak lanjut',
            ],
        ];
    }
}
