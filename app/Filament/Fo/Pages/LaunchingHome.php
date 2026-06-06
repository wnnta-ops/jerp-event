<?php

namespace App\Filament\Fo\Pages;

use App\Modules\FO\Launching\Models\NupRegistration;
use App\Modules\FO\MarketingSales\Models\Unit;
use Filament\Pages\Page;

/**
 * Launching sub-dashboard — landing page after user clicks the "Launching" tile
 * on the FO main dashboard. Hosts a grid of 3 sub-modules:
 *   - Aktivasi NUP   : open/close event registration + activate event for the day
 *   - Pilih Unit     : real-time live unit map (UnitPetaLive)
 *   - Closing NUP    : 4-step wizard to finalize NUP → Reservasi on launching day
 *
 * Each sub-module's actual workflow is implemented in subsequent iterations.
 */
class LaunchingHome extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-megaphone';

    protected static ?string $title = 'Launching';

    protected static ?string $navigationLabel = 'Launching';

    protected static ?string $slug = 'launching';

    protected static string $view = 'filament.fo.pages.launching-home';

    /**
     * Hide from the sidebar — entry point is the dashboard tile.
     * (Sidebar still rendered with sub-module items when on this page.)
     */
    protected static bool $shouldRegisterNavigation = false;

    public function getSubTiles(): array
    {
        return [
            [
                'label' => 'Aktivasi NUP',
                'sub' => 'Check-in NUP hari-H sebelum pilih unit',
                'url' => url('/fo/aktivasi-nup'),
                'icon' => 'M12 11c0-1.1.9-2 2-2s2 .9 2 2v3M5 11h14a1 1 0 011 1v8a1 1 0 01-1 1H5a1 1 0 01-1-1v-8a1 1 0 011-1zM8 11V7a4 4 0 118 0v4',
                'gradient' => 'linear-gradient(135deg, #ef4444 0%, #991b1b 100%)',
                'count' => fn () => NupRegistration::where('verif_status', 'APPROVED')
                    ->whereNotNull('no_nup')
                    ->where('is_hold', false)
                    ->whereNotIn('status', ['CHECK_IN', 'PILIH_UNIT', 'BATAL', 'TIDAK_HADIR'])
                    ->count(),
                'count_label' => 'siap aktivasi',
            ],
            [
                'label' => 'Pilih Unit',
                'sub' => 'Siteplan + scan barcode + pilih unit hari-H',
                'url' => url('/fo/pilih-unit'),
                'icon' => 'M3 6l6-3 6 3 6-3v15l-6 3-6-3-6 3V6zM9 3v15M15 6v15',
                'gradient' => 'linear-gradient(135deg, #ec4899 0%, #be185d 100%)',
                'count' => fn () => Unit::where('status', 'TERSEDIA')->count(),
                'count_label' => 'unit tersedia',
            ],
            [
                'label' => 'Closing NUP',
                'sub' => 'Pilih skema & sales → Kontrak + TTS',
                'url' => url('/fo/closing-nup'),
                'icon' => 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z',
                'gradient' => 'linear-gradient(135deg, #a855f7 0%, #6b21a8 100%)',
                'count' => fn () => NupRegistration::where('status', 'PILIH_UNIT')
                    ->whereNotNull('reservasi_id')
                    ->whereHas('reservasi', fn ($q) => $q->whereDoesntHave('kontrak'))
                    ->count(),
                'count_label' => 'siap closing',
            ],
        ];
    }
}
