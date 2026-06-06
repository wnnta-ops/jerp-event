<?php

namespace App\Filament\Fo\Pages;

use App\Modules\FO\FinanceAR\Models\Bkm;
use App\Modules\FO\FinanceAR\Models\Memo;
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
                'label' => 'Tambah DP',
                'sub' => 'Tambah uang muka (opsional)',
                'url' => '#',
                'icon' => 'M12 4v16m8-8H4',
                'gradient' => 'linear-gradient(135deg, #6366f1 0%, #4338ca 100%)',
                'count' => fn () => null,
                'count_label' => '',
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
        ];
    }
}
