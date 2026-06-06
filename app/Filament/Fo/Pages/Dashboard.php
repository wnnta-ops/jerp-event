<?php

namespace App\Filament\Fo\Pages;

use App\Modules\FO\Approval\Models\Approval;
use App\Modules\FO\FinanceAR\Models\Tts;
use App\Modules\FO\Komisi\Models\Komisi;
use App\Modules\FO\Launching\Models\NupEvent;
use App\Modules\FO\Launching\Models\NupRegistration;
use App\Modules\FO\MarketingSales\Models\Customer;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use App\Modules\FO\MarketingSales\Models\Unit;
use Filament\Pages\Dashboard as BaseDashboard;

/**
 * Modern colorful-tile dashboard for the Front Office panel, inspired by the
 * legacy eProperty homepage (gateway.aspx). Replaces the default Filament
 * Dashboard. Stat widgets still render below the tile grid via the view.
 */
class Dashboard extends BaseDashboard
{
    protected static ?string $navigationIcon = 'heroicon-o-home';

    protected static ?string $title = 'Dashboard';

    protected static string $view = 'filament.fo.pages.dashboard';

    protected static ?int $navigationSort = -100;

    /**
     * Tiles displayed on the home grid. Each one navigates to a Resource or
     * Page in the FO panel. `count` is a callable for live badge numbers.
     */
    public function getTiles(): array
    {
        return [
            [
                'label' => 'Marketing & Sales',
                'sub' => 'Customer, Reservasi, Kontrak',
                'url' => url('/fo/marketing'),
                'icon' => 'M3 21h18M3 7l9-4 9 4M5 21V11h14v10M9 21v-6h6v6',
                'gradient' => 'linear-gradient(135deg, #f97316 0%, #ea580c 100%)',
                'count' => fn () => Kontrak::count(),
                'count_label' => 'kontrak',
            ],
            [
                'label' => 'Setup Sales',
                'sub' => 'Skema, Price List, Gimmick, Unit Type',
                'url' => url('/fo/skemas'),
                'icon' => 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065zM15 12a3 3 0 11-6 0 3 3 0 016 0z',
                'gradient' => 'linear-gradient(135deg, #06b6d4 0%, #0284c7 100%)',
                'count' => fn () => Unit::count(),
                'count_label' => 'unit',
            ],
            [
                'label' => 'Finance AR',
                'sub' => 'TTS, Kwitansi, Tambah DP, VA, MEMO',
                'url' => url('/fo/finance-ar'),
                'icon' => 'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8V4m0 12v2M5 12H3m18 0h-2',
                'gradient' => 'linear-gradient(135deg, #10b981 0%, #047857 100%)',
                'count' => fn () => Tts::where('status', 'DRAFT')->count(),
                'count_label' => 'TTS draft',
            ],
            [
                'label' => 'Collection',
                'sub' => 'Tunggakan, PJT, SKL',
                'url' => '#',
                'icon' => 'M3 14l3-7h12l3 7M3 14v7h18v-7M5 14h2m2 0h2m2 0h2m2 0h2',
                'gradient' => 'linear-gradient(135deg, #ec4899 0%, #be185d 100%)',
                'count' => fn () => null,
                'count_label' => 'menyusul',
                'disabled' => true,
            ],
            [
                'label' => 'Legal',
                'sub' => 'PPJB, AJB, IMB, Sertifikat',
                'url' => '#',
                'icon' => 'M12 3l8 4v5c0 5-3.5 8.5-8 9-4.5-.5-8-4-8-9V7l8-4zM9 12l2 2 4-4',
                'gradient' => 'linear-gradient(135deg, #14b8a6 0%, #0f766e 100%)',
                'count' => fn () => null,
                'count_label' => 'menyusul',
                'disabled' => true,
            ],
            [
                'label' => 'Approval',
                'sub' => 'Diskon, batal, adj harga, refund',
                'url' => url('/fo/approvals'),
                'icon' => 'M9 12l2 2 4-4M7.835 4.697A3.42 3.42 0 009.42 2.422c.62-1.27 2.54-1.27 3.16 0a3.42 3.42 0 001.585 2.275c1.207.612 1.207 2.31 0 2.922a3.42 3.42 0 00-1.585 2.275c-.62 1.27-2.54 1.27-3.16 0a3.42 3.42 0 00-1.585-2.275c-1.207-.612-1.207-2.31 0-2.922z',
                'gradient' => 'linear-gradient(135deg, #ca8a04 0%, #a16207 100%)',
                'count' => fn () => Approval::where('status', 'PENDING')->count(),
                'count_label' => 'pending',
            ],
            [
                'label' => 'Launching',
                'sub' => 'Aktivasi NUP, Pilih Unit, Closing NUP',
                'url' => url('/fo/launching'),
                'icon' => 'M5 19l3-3m6-6l3-3m-9 9c2-5 7-10 12-9 1 5-4 10-9 12l-3-3z',
                'gradient' => 'linear-gradient(135deg, #06b6d4 0%, #0e7490 100%)',
                'count' => fn () => NupEvent::where('status', 'OPEN')->count(),
                'count_label' => 'event aktif',
            ],
            [
                'label' => 'NUP',
                'sub' => 'Pendaftaran & verifikasi',
                'url' => url('/fo/verifikasi-nups'),
                'icon' => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2',
                'gradient' => 'linear-gradient(135deg, #d946ef 0%, #a21caf 100%)',
                'count' => fn () => NupRegistration::where('status', 'DAFTAR')->count(),
                'count_label' => 'baru daftar',
            ],
            [
                'label' => 'Komisi',
                'sub' => 'Agent, komisi, reward',
                'url' => url('/fo/komisis'),
                'icon' => 'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8V4m0 12v2M5 12H3m18 0h-2',
                'gradient' => 'linear-gradient(135deg, #a855f7 0%, #6b21a8 100%)',
                'count' => fn () => Komisi::where('status', 'APPROVED')->count(),
                'count_label' => 'siap dibayar',
            ],
            // Tile "Setup Unit" dihilangkan: master Lokasi & Tahap sudah dapat
            // diakses dari navigasi grup "Setup Unit" (lihat FoPanelProvider),
            // jadi tidak perlu duplikat di dashboard.
            [
                'label' => 'Pengaturan',
                'sub' => 'Default booking fee, tenor, dst.',
                'url' => url('/fo/pengaturan-fo'),
                'icon' => 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065zM15 12a3 3 0 11-6 0 3 3 0 016 0z',
                'gradient' => 'linear-gradient(135deg, #64748b 0%, #334155 100%)',
                'count' => fn () => null,
                'count_label' => '',
            ],
            [
                'label' => 'Master Customer',
                'sub' => 'Verifikasi lead, terbitkan no.',
                'url' => url('/fo/customers'),
                'icon' => 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5 5 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z',
                'gradient' => 'linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%)',
                'count' => fn () => Customer::where('lead_status', 'LEAD')->count(),
                'count_label' => 'menunggu verifikasi',
            ],
        ];
    }

    /**
     * Hide header widgets so the grid is the whole dashboard.
     */
    public function getHeaderWidgets(): array
    {
        return [];
    }

    public function getFooterWidgets(): array
    {
        return [];
    }
}
