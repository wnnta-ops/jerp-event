<?php

namespace App\Filament\Fo\Pages;

use App\Modules\FO\MarketingSales\Models\Customer;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use App\Modules\FO\MarketingSales\Models\Reservasi;
use Filament\Pages\Page;

/**
 * Marketing & Sales sub-dashboard — landing page after the user clicks the
 * "Marketing & Sales" tile on the FO main dashboard. Hosts a grid of the
 * module's sub-menus (Master Customer, Reservasi, Kontrak), mirroring the
 * Launching Center hub. Full-screen (sidebar hidden via FoPanelProvider).
 */
class MarketingHome extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-shopping-bag';

    protected static ?string $title = 'Marketing & Sales';

    protected static ?string $navigationLabel = 'Marketing & Sales';

    protected static ?string $slug = 'marketing';

    protected static string $view = 'filament.fo.pages.module-hub';

    protected static bool $shouldRegisterNavigation = false;

    public function getHubBreadcrumb(): string
    {
        return 'Marketing & Sales';
    }

    public function getHubTitle(): string
    {
        return '🏠 Marketing & Sales';
    }

    public function getHubSubtitle(): string
    {
        return 'Kelola customer, reservasi unit, dan kontrak penjualan.';
    }

    public function getHubGradient(): string
    {
        return '#ff8c00';
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getSubTiles(): array
    {
        return [
            [
                'label' => 'Master Customer',
                'sub' => 'Data customer + kartu QR',
                'url' => url('/fo/sales'),
                'icon' => 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5 5 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z',
                'gradient' => '#ff8c00',
                'count' => fn () => Customer::count(),
                'count_label' => 'customer',
            ],
            [
                'label' => 'Reservasi',
                'sub' => 'Booking unit (UTJ)',
                'url' => url('/fo/reservasis'),
                'icon' => 'M5 5a2 2 0 012-2h10a2 2 0 012 2v16l-7-3.5L5 21V5z',
                'gradient' => '#ff8c00',
                'count' => fn () => Reservasi::where('status', 'AKTIF')->count(),
                'count_label' => 'aktif',
            ],
            [
                'label' => 'Kontrak',
                'sub' => 'Kontrak penjualan + cetak SPR',
                'url' => url('/fo/kontraks'),
                'icon' => 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
                'gradient' => '#ff8c00',
                'count' => fn () => Kontrak::count(),
                'count_label' => 'kontrak',
            ],
        ];
    }
}
