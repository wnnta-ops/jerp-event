<?php

namespace App\Providers\Filament;

use App\Filament\Fo\Pages\Dashboard;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\MenuItem;
use Filament\Navigation\NavigationBuilder;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use App\Support\Akses;

class FoPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('fo')
            ->path('fo')
            ->brandName('Java Core System')
            ->favicon(asset('images/logo/logo-daun.png'))
            // TANPA ->login(): tamu yg buka /fo diarahkan ke /login (Livewire) — satu
            // pintu login untuk seluruh J-ERP. /fo/login dihapus.
            // Warna brand mengikuti ipro-fo (oranye hangat #ff8c00 / Java Core).
            ->colors([
                'primary' => Color::Orange,
                'gray' => Color::Slate,
                'success' => Color::Emerald,
                'warning' => Color::Amber,
                'danger' => Color::Rose,
                'info' => Color::Sky,
            ])
            ->darkMode()
            ->sidebarCollapsibleOnDesktop()
            ->font('Inter')
            // Menu silang ke BO/Bankloan ipro-fo via SSO token-handoff (1 login).
            ->userMenuItems([
                MenuItem::make()
                    ->label('Buka BO / Bankloan')
                    ->url(fn () => url('/sso/to-bo'))
                    ->icon('heroicon-o-building-library'),
            ])
            ->discoverResources(in: app_path('Filament/Fo/Resources'), for: 'App\\Filament\\Fo\\Resources')
            ->discoverPages(in: app_path('Filament/Fo/Pages'), for: 'App\\Filament\\Fo\\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Fo/Widgets'), for: 'App\\Filament\\Fo\\Widgets')
            ->widgets([])
            // Sidebar PENUH semua modul FO (mirip ipro-fo) — grup collapsible, selalu tampil.
            ->navigation(fn (NavigationBuilder $builder) => $this->buildFullNavigation($builder))
            // Sembunyikan sidebar HANYA di halaman live full-screen (Launching/Pilih Unit/Siteplan live).
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn () => $this->isFullscreenPage()
                    ? '<style>'
                        .'aside.fi-sidebar, .fi-sidebar-nav, .fi-topbar-open-sidebar-btn, .fi-topbar-close-sidebar-btn { display: none !important; }'
                        .'.fi-main-ctn { padding-inline-start: 0 !important; }'
                        .'.fi-main { max-width: 100% !important; }'
                        .'@media (min-width: 1024px) { .fi-layout { grid-template-columns: 0 1fr !important; } }'
                        .'</style>'
                    : ''
            )
            // Theme global FO (semua halaman) — sidebar/kartu/tombol ala ipro-fo.
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn () => view('filament.fo.theme-style')->render()
            )
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }

    /**
     * Sidebar PENUH: semua modul FO jadi grup collapsible (mirip sidebar ipro-fo).
     */
    private function buildFullNavigation(NavigationBuilder $builder): NavigationBuilder
    {
        $groups = [];
        foreach ($this->moduleDefinitions() as $key => $mod) {
            if ($key === 'setup-unit') {
                continue; // duplikat dari 'pengaturan'
            }
            $items = [];
            if (! empty($mod['groups'])) {
                foreach ($mod['groups'] as $g) {
                    $items = array_merge($items, $g['items']);
                }
            } else {
                $items = $mod['items'] ?? [];
            }
            if (empty($items)) {
                continue;
            }
            $groups[] = NavigationGroup::make($mod['label'])
                ->icon($mod['icon'])
                ->items($items)
                ->collapsible();
        }

        return $builder
            ->items([
                NavigationItem::make('Dashboard')
                    ->url(url('/fo'))
                    ->icon('heroicon-o-squares-2x2')
                    ->isActiveWhen(fn () => trim((string) request()->path(), '/') === 'fo')
                    ->sort(-100),
                // Kembali ke hub utama J-ERP (Front Office / Back Office / Backup).
                NavigationItem::make('Kembali ke Dashboard Utama')
                    ->url(url('/dashboard'))
                    ->icon('heroicon-o-arrow-left-circle')
                    ->sort(-99),
            ])
            ->groups($groups);
    }

    /** Halaman live full-screen (sidebar disembunyikan): Launching/Pilih Unit/Siteplan live. */
    private function isFullscreenPage(): bool
    {
        return in_array(trim((string) request()->path(), '/'), [
            'fo/launching', 'fo/aktivasi-nup', 'fo/pilih-unit', 'fo/peta-unit-live', 'fo/closing-nup',
            'fo/asisten-ai',
        ], true);
    }

    /**
     * Build sidebar navigation scoped to the current URL's module. Mirrors the
     * legacy eProperty UX where each tile opens its own sidebar.
     */
    private function buildScopedNavigation(NavigationBuilder $builder): NavigationBuilder
    {
        // /fo (dashboard) — no items (sidebar hidden via CSS anyway)
        if ($this->isOnDashboardHome()) {
            return $builder->items([]);
        }

        $segment = request()->segment(2);
        $module = $this->moduleForSegment((string) $segment);

        // Always-present items: back to dashboard FO + back to main app
        $alwaysItems = [
            NavigationItem::make('Kembali ke Dashboard FO')
                ->url(url('/fo'))
                ->icon('heroicon-o-squares-2x2')
                ->sort(-100),
            NavigationItem::make('Menu utama (Java Core)')
                ->url(url('/dashboard'))
                ->icon('heroicon-o-arrow-left-circle')
                ->sort(-99),
        ];

        // Unknown / unmapped page: just back links
        if (! $module) {
            return $builder->items($alwaysItems);
        }

        // Modul boleh punya BEBERAPA sub-grup ('groups') seperti legacy
        // (Komisi: Setup, BLT, Pengajuan, Realisasi, Reward) — atau satu daftar
        // 'items' (satu grup). Render sesuai yang tersedia.
        if (! empty($module['groups'])) {
            $groups = [];
            foreach ($module['groups'] as $g) {
                $group = NavigationGroup::make($g['label'])->items($g['items']);
                if (! empty($g['icon'])) {
                    $group->icon($g['icon']);
                }
                $groups[] = $group;
            }

            return $builder->items($alwaysItems)->groups($groups);
        }

        return $builder
            ->items($alwaysItems)
            ->groups([
                NavigationGroup::make($module['label'])
                    ->icon($module['icon'])
                    ->items($module['items']),
            ]);
    }

    /**
     * Halaman full-screen tanpa sidebar — dashboard utama + landing Launching
     * + sub-page Launching (Aktivasi NUP, Pilih Unit, Closing NUP).
     */
    private function isOnDashboardHome(): bool
    {
        $path = trim((string) request()->path(), '/');

        return in_array($path, [
            'fo',
            'fo/launching',
            'fo/aktivasi-nup',
            'fo/pilih-unit',
            'fo/peta-unit-live',
            'fo/closing-nup',
            'fo/marketing',
            'fo/finance-ar',
        ], true);
    }

    /**
     * @return array{label:string,icon:string,items:array<int,NavigationItem>}|null
     */
    private function moduleForSegment(string $segment): ?array
    {
        $segmentToModule = [
            // Marketing & Sales (daftar customer kini di /fo/sales)
            'sales' => 'marketing',
            'reservasis' => 'marketing',
            'kontraks' => 'marketing',
            'spr' => 'marketing',
            'adjustment-kontrak' => 'marketing',
            'pembatalan-kontrak' => 'marketing',
            'pengalihan-hak' => 'marketing',
            'pindah-unit' => 'marketing',
            'pindah-unit-pilih' => 'marketing',
            // Setup Sales (master + sales + unit + gimmick)
            'skemas' => 'setup',
            'sumber-data' => 'setup',
            'sales-types' => 'setup',
            'sales-levels' => 'setup',
            'agents' => 'setup',
            'price-lists' => 'setup',
            'gimmicks' => 'setup',
            'tipe-gimmick' => 'setup',
            'unit-types' => 'setup',
            'units' => 'setup',
            'upload-unit' => 'setup',
            'upload-price-list' => 'setup',
            'upload-site-plan' => 'setup',
            'upload-koordinat' => 'setup',
            'upload-koordinat-editor' => 'setup',
            // Finance AR
            'asisten-ai' => 'finance',
            'tts' => 'finance',
            'pembayaran-ar' => 'finance',
            'pelunasan-angsuran' => 'finance',
            'pengajuan-kpr' => 'finance',
            'setup-hold' => 'finance',
            'bkms' => 'finance',
            'vas' => 'finance',
            'memos' => 'finance',
            'fps' => 'finance',
            'bgs' => 'finance',
            'refunds' => 'finance',
            'transfer-anonims' => 'finance',
            'gmcs' => 'finance',
            'reminder-ar' => 'finance',
            // Collection (penagihan AR setelah SPR)
            'collection' => 'collection',
            'pemberkasan' => 'collection',
            'collection-setup-sp' => 'collection',
            'collection-termin-sp' => 'collection',
            // Approval
            'approvals' => 'approval',
            'approval-pengalihan-hak' => 'approval',
            'approval-pindah-unit' => 'approval',
            'approval-pembatalan' => 'approval',
            'approval-diskon' => 'approval',
            'approval-adjustment' => 'approval',
            'approval-reschedule' => 'approval',
            'approval-customize' => 'approval',
            'approval-blt' => 'approval',
            'approval-komisi' => 'approval',
            'setup-approval' => 'approval',
            'approval-log' => 'approval',
            // NUP (Setup + Event + Pendaftaran + Verifikasi Data)
            'nup-tipes' => 'nup',
            'setup-bank' => 'nup',
            'setup-preferensi-unit' => 'nup',
            'data-preferensi-unit' => 'nup',
            'nup-events' => 'nup',
            'nup-registrations' => 'nup',
            'verifikasi-nups' => 'nup',
            'verifikasi-booked' => 'marketing',
            // Launching (Aktivasi NUP + Pilih Unit + Closing NUP) — sidebar
            // disembunyikan via CSS untuk semua sub-pagenya.
            'launching' => 'launching',
            'aktivasi-nup' => 'launching',
            'pilih-unit' => 'launching',
            'peta-unit-live' => 'launching',
            'closing-nup' => 'launching',
            // Komisi (agents pindah ke setup karena Pendaftaran Sales)
            'komisis' => 'komisi',
            'generate-komisi' => 'komisi',
            'pengajuan-komisi' => 'komisi',
            'realisasi-komisi' => 'komisi',
            'generate-blt' => 'komisi',
            'pengajuan-blt' => 'komisi',
            'realisasi-blt' => 'komisi',
            'rewards' => 'komisi',
            'tipe-tarif' => 'komisi',
            'skema-komisi' => 'komisi',
            'skema-blt' => 'komisi',
            'termin-blt' => 'komisi',
            'termin-komisi' => 'komisi',
            'skema-reward' => 'komisi',
            // Pengaturan + Setup Unit (master Lokasi & Tahap)
            'pengaturan-fo' => 'pengaturan',
            'lokasis' => 'setup-unit',
            'tahaps' => 'setup-unit',
        ];

        // Halaman placeholder generik (SegeraHadir) dipakai banyak menu; modul
        // ditentukan dari query ?modul= agar sidebar yang benar yang tampil.
        if ($segment === 'segera-hadir') {
            $key = match ((string) request()->query('modul', '')) {
                'Komisi' => 'komisi',
                'Marketing & Sales' => 'marketing',
                'Report' => 'report',
                default => null,
            };

            return $key ? ($this->moduleDefinitions()[$key] ?? null) : null;
        }

        $key = $segmentToModule[$segment] ?? null;

        return $key ? ($this->moduleDefinitions()[$key] ?? null) : null;
    }

    /**
     * @return array<string,array{label:string,icon:string,items:array<int,NavigationItem>}>
     */
    private function moduleDefinitions(): array
    {
        $item = function (string $label, string $url, string $icon, string $activeRoutePrefix) {
            // Hitung path SEKALI di sini (bukan parse_url tiap render di isActiveWhen).
            $path = trim((string) parse_url($url, PHP_URL_PATH), '/');

            return NavigationItem::make($label)
                ->url(url($url))
                ->icon($icon)
                // Sidebar ini dirakit manual, jadi Filament tidak pernah menanyakan
                // canViewAny()/canAccess() seperti pada navigasi otomatis. Tanpa baris
                // ini, grup tanpa satu izin pun tetap melihat SELURUH menu FO —
                // rutenya memang tetap tertutup, tetapi daftar menunya bocor.
                ->visible(fn () => Akses::bolehRute($activeRoutePrefix))
                ->isActiveWhen(fn () => request()->routeIs($activeRoutePrefix.'*') || request()->is($path.'*'));
        };

        // Item placeholder: struktur dipasang, flow/logika menyusul. Mengarah ke
        // halaman SegeraHadir dengan label modul+fitur via query string.
        $soon = fn (string $label, string $modul, string $fitur, string $icon = 'heroicon-o-wrench-screwdriver') => NavigationItem::make($label)
            ->url(url('/fo/segera-hadir?modul='.rawurlencode($modul).'&fitur='.rawurlencode($fitur)))
            ->icon($icon)
            ->visible(fn () => Akses::bolehRute('filament.fo.pages.segera-hadir'))
            ->isActiveWhen(fn () => request()->is('fo/segera-hadir') && request()->query('fitur') === $fitur);

        return [
            'marketing' => [
                'label' => 'Marketing & Sales',
                'icon' => 'heroicon-o-shopping-bag',
                'groups' => [
                    ['label' => 'Customer & Booking', 'items' => [
                        $item('Customer', '/fo/sales', 'heroicon-o-user-group', 'filament.fo.resources.sales.'),
                        $item('Master Booking', '/fo/reservasis', 'heroicon-o-bookmark', 'filament.fo.resources.reservasis.'),
                    ]],
                    ['label' => 'Booked Manual', 'items' => [
                        $item('Verifikasi Data Booked', '/fo/verifikasi-booked', 'heroicon-o-clipboard-document-check', 'filament.fo.resources.verifikasi-booked.'),
                    ]],
                    ['label' => 'Kontrak', 'items' => [
                        $item('SPRS (Surat Pemesanan)', '/fo/kontraks', 'heroicon-o-document-text', 'filament.fo.resources.kontraks.'),
                        $item('SPR (Final)', '/fo/spr', 'heroicon-o-document-check', 'filament.fo.resources.spr.'),
                        $item('Adjustment Kontrak', '/fo/adjustment-kontrak', 'heroicon-o-adjustments-horizontal', 'filament.fo.pages.adjustment-kontrak'),
                        $item('Pengalihan Hak', '/fo/pengalihan-hak', 'heroicon-o-identification', 'filament.fo.resources.pengalihan-hak.'),
                        $item('Pindah Unit', '/fo/pindah-unit', 'heroicon-o-arrows-right-left', 'filament.fo.resources.pindah-unit.'),
                        $item('Pembatalan Kontrak', '/fo/pembatalan-kontrak', 'heroicon-o-x-circle', 'filament.fo.pages.pembatalan-kontrak'),
                    ]],
                    ['label' => 'Laporan', 'items' => [
                        $item('Laporan Penjualan', '/fo/laporan-penjualan', 'heroicon-o-chart-bar', 'filament.fo.pages.laporan-penjualan'),
                    ]],
                ],
            ],
            'setup' => [
                'label' => 'Setup Sales',
                'icon' => 'heroicon-o-cog-6-tooth',
                'items' => [
                    $item('Skema Bayar', '/fo/skemas', 'heroicon-o-table-cells', 'filament.fo.resources.skemas.'),
                    $item('Tipe Sales', '/fo/sales-types', 'heroicon-o-tag', 'filament.fo.resources.sales-types.'),
                    $item('Level Sales', '/fo/sales-levels', 'heroicon-o-bars-arrow-down', 'filament.fo.resources.sales-levels.'),
                    $item('Pendaftaran Sales', '/fo/agents', 'heroicon-o-user-plus', 'filament.fo.resources.agents.'),
                    $item('Sumber Data', '/fo/sumber-data', 'heroicon-o-megaphone', 'filament.fo.resources.sumber-data.'),
                    $item('Price List', '/fo/price-lists', 'heroicon-o-currency-dollar', 'filament.fo.resources.price-lists.'),
                    $item('Tipe Gimmick', '/fo/tipe-gimmick', 'heroicon-o-squares-2x2', 'filament.fo.resources.tipe-gimmick.'),
                    $item('Pendaftaran Gimmick', '/fo/gimmicks', 'heroicon-o-sparkles', 'filament.fo.resources.gimmicks.'),
                    $item('Tipe Unit', '/fo/unit-types', 'heroicon-o-cube', 'filament.fo.resources.unit-types.'),
                    $item('Unit', '/fo/units', 'heroicon-o-home-modern', 'filament.fo.resources.units.'),
                    $item('Upload Unit', '/fo/upload-unit', 'heroicon-o-arrow-up-tray', 'filament.fo.pages.upload-unit'),
                    $item('Upload Price List', '/fo/upload-price-list', 'heroicon-o-arrow-up-tray', 'filament.fo.pages.upload-price-list'),
                    $item('Upload Site Plan', '/fo/upload-site-plan', 'heroicon-o-photo', 'filament.fo.pages.upload-site-plan'),
                    $item('Upload Koordinat', '/fo/upload-koordinat', 'heroicon-o-map-pin', 'filament.fo.pages.upload-koordinat'),
                    $item('Polygon Editor', '/fo/upload-koordinat-editor', 'heroicon-o-cursor-arrow-rays', 'filament.fo.pages.upload-koordinat-editor'),
                ],
            ],
            'finance' => [
                'label' => 'Finance AR',
                'icon' => 'heroicon-o-banknotes',
                'items' => [
                    $item('Transfer Anonim', '/fo/transfer-anonims', 'heroicon-o-question-mark-circle', 'filament.fo.resources.transfer-anonims.'),
                    $item('TTS', '/fo/tts', 'heroicon-o-ticket', 'filament.fo.resources.tts.'),
                    $item('Pembayaran AR', '/fo/pembayaran-ar', 'heroicon-o-clipboard-document-list', 'filament.fo.pages.pembayaran-ar'),
                    $item('Pelunasan DP, Angsuran & TDP', '/fo/pelunasan-angsuran', 'heroicon-o-banknotes', 'filament.fo.pages.pelunasan-angsuran'),
                    // Menu "Pengajuan KPR" di-hide dari sidebar (permintaan). Page & route tetap ada (URL /fo/pengajuan-kpr).
                    // $item('Pengajuan KPR', '/fo/pengajuan-kpr', 'heroicon-o-building-library', 'filament.fo.pages.pengajuan-kpr'),
                    $item('Kwitansi', '/fo/bkms', 'heroicon-o-banknotes', 'filament.fo.resources.bkms.'),
                    $item('Faktur Penjualan', '/fo/fps', 'heroicon-o-receipt-percent', 'filament.fo.resources.fps.'),
                    $item('Bilyet Giro', '/fo/bgs', 'heroicon-o-document-currency-dollar', 'filament.fo.resources.bgs.'),
                    $item('Refund / CB', '/fo/refunds', 'heroicon-o-arrow-uturn-left', 'filament.fo.resources.refunds.'),
                    $item('GMC (Gimmick)', '/fo/gmcs', 'heroicon-o-gift', 'filament.fo.resources.gmcs.'),
                    $item('Setup Hold', '/fo/setup-hold', 'heroicon-o-pause-circle', 'filament.fo.pages.setup-hold'),
                    $item('Report Xendit', '/fo/report-xendit', 'heroicon-o-credit-card', 'filament.fo.pages.report-xendit'),
                    $item('Dashboard AR', '/fo/reminder-ar', 'heroicon-o-presentation-chart-line', 'filament.fo.pages.reminder-ar'),
                ],
            ],
            'collection' => [
                'label' => 'Collection',
                'icon' => 'heroicon-o-banknotes',
                'groups' => [
                    ['label' => 'Penagihan', 'items' => [
                        $item('Surat SP', '/fo/collection', 'heroicon-o-document-text', 'filament.fo.resources.collection.'),
                        $item('Pemberkasan', '/fo/pemberkasan', 'heroicon-o-document-check', 'filament.fo.resources.pemberkasan.'),
                    ]],
                    ['label' => 'Setup', 'items' => [
                        $item('Setup SP', '/fo/collection-setup-sp', 'heroicon-o-document-text', 'filament.fo.pages.collection-setup-sp'),
                        $item('Setup Termin SP', '/fo/collection-termin-sp', 'heroicon-o-clock', 'filament.fo.pages.collection-termin-sp'),
                    ]],
                ],
            ],
            'approval' => [
                'label' => 'Approval',
                'icon' => 'heroicon-o-clipboard-document-check',
                'items' => [
                    $item('Approval Pengalihan Hak', '/fo/approval-pengalihan-hak', 'heroicon-o-identification', 'filament.fo.resources.approval-pengalihan-hak.'),
                    $item('Approval Pindah Unit', '/fo/approval-pindah-unit', 'heroicon-o-arrows-right-left', 'filament.fo.resources.approval-pindah-unit.'),
                    $item('Approval Pembatalan', '/fo/approval-pembatalan', 'heroicon-o-x-circle', 'filament.fo.resources.approval-pembatalan.'),
                    $item('Approval Diskon', '/fo/approval-diskon', 'heroicon-o-receipt-percent', 'filament.fo.resources.approval-diskon.'),
                    $item('Approval Diskon SPRS', '/fo/approval-diskon-sprs', 'heroicon-o-receipt-percent', 'filament.fo.resources.approval-diskon-sprs.'),
                    $item('Approval Adjustment', '/fo/approval-adjustment', 'heroicon-o-adjustments-horizontal', 'filament.fo.resources.approval-adjustment.'),
                    $item('Approval Reschedule', '/fo/approval-reschedule', 'heroicon-o-calendar-days', 'filament.fo.resources.approval-reschedule.'),
                    $item('Approval Customize', '/fo/approval-customize', 'heroicon-o-wrench-screwdriver', 'filament.fo.resources.approval-customize.'),
                    $item('Approval BLT', '/fo/approval-blt', 'heroicon-o-banknotes', 'filament.fo.resources.approval-blt.'),
                    $item('Approval Komisi', '/fo/approval-komisi', 'heroicon-o-receipt-percent', 'filament.fo.resources.approval-komisi.'),
                    $item('Setup Approval', '/fo/setup-approval', 'heroicon-o-user-plus', 'filament.fo.pages.setup-approval'),
                    $item('Log File', '/fo/approval-log', 'heroicon-o-document-text', 'filament.fo.pages.approval-log'),
                ],
            ],
            'nup' => [
                'label' => 'NUP',
                'icon' => 'heroicon-o-queue-list',
                'items' => [
                    $item('Setup NUP', '/fo/nup-tipes', 'heroicon-o-adjustments-horizontal', 'filament.fo.resources.nup-tipes.'),
                    $item('Setup Bank', '/fo/setup-bank', 'heroicon-o-building-library', 'filament.fo.resources.setup-bank.'),
                    $item('Setup Preferensi Unit', '/fo/setup-preferensi-unit', 'heroicon-o-home-modern', 'filament.fo.resources.setup-preferensi-unit.'),
                    $item('Data Preferensi Unit', '/fo/data-preferensi-unit', 'heroicon-o-clipboard-document-list', 'filament.fo.resources.data-preferensi-unit.'),
                    $item('Event Launching', '/fo/nup-events', 'heroicon-o-megaphone', 'filament.fo.resources.nup-events.'),
                    $item('Pendaftaran NUP', '/fo/nup-registrations', 'heroicon-o-queue-list', 'filament.fo.resources.nup-registrations.'),
                    $item('Verifikasi Data', '/fo/verifikasi-nups', 'heroicon-o-clipboard-document-check', 'filament.fo.resources.verifikasi-nups.'),
                    $item('Report SPR Bank', '/fo/report-spr-bank', 'heroicon-o-building-library', 'filament.fo.resources.report-spr-bank.'),
                    $item('Report NUP', '/fo/report-nup', 'heroicon-o-document-chart-bar', 'filament.fo.pages.report-nup'),
                ],
            ],
            // Launching tetap di-mapping (Aktivasi NUP, Pilih Unit, Closing NUP)
            // tapi sidebar disembunyikan via render hook. Items hanya dipakai
            // kalau suatu saat ingin show sidebar di sub-pages launching.
            'launching' => [
                'label' => 'Launching',
                'icon' => 'heroicon-o-rocket-launch',
                'items' => [
                    $item('Aktivasi NUP', '/fo/launching', 'heroicon-o-lock-open', 'filament.fo.pages.aktivasi-nup'),
                    $item('Pilih Unit (Live)', '/fo/launching', 'heroicon-o-map', 'filament.fo.pages.peta-unit-live'),
                    $item('Closing NUP', '/fo/launching', 'heroicon-o-check-circle', 'filament.fo.pages.closing-nup'),
                ],
            ],
            'komisi' => [
                'label' => 'Komisi',
                'icon' => 'heroicon-o-receipt-percent',
                'groups' => [
                    ['label' => 'Setup', 'items' => [
                        $item('Setup Tarif', '/fo/tipe-tarif', 'heroicon-o-calculator', 'filament.fo.resources.tipe-tarif.'),
                        $item('Skema Komisi', '/fo/skema-komisi', 'heroicon-o-table-cells', 'filament.fo.resources.skema-komisi.'),
                        $item('Termin Komisi', '/fo/termin-komisi', 'heroicon-o-list-bullet', 'filament.fo.resources.termin-komisi.'),
                        $item('Skema BLT', '/fo/skema-blt', 'heroicon-o-table-cells', 'filament.fo.resources.skema-blt.'),
                        $item('Termin BLT', '/fo/termin-blt', 'heroicon-o-list-bullet', 'filament.fo.resources.termin-blt.'),
                        $item('Setup Skema Reward', '/fo/skema-reward', 'heroicon-o-gift', 'filament.fo.resources.skema-reward.'),
                    ]],
                    ['label' => 'BLT', 'items' => [
                        $item('Generate BLT', '/fo/generate-blt', 'heroicon-o-bolt', 'filament.fo.pages.generate-blt'),
                        $item('Pengajuan BLT', '/fo/pengajuan-blt', 'heroicon-o-paper-airplane', 'filament.fo.pages.pengajuan-blt'),
                        $item('Realisasi BLT', '/fo/realisasi-blt', 'heroicon-o-banknotes', 'filament.fo.pages.realisasi-blt'),
                    ]],
                    ['label' => 'Komisi', 'items' => [
                        $item('Generate Komisi', '/fo/generate-komisi', 'heroicon-o-bolt', 'filament.fo.pages.generate-komisi'),
                        $item('Pengajuan Komisi', '/fo/pengajuan-komisi', 'heroicon-o-paper-airplane', 'filament.fo.pages.pengajuan-komisi'),
                        $item('Realisasi Komisi', '/fo/realisasi-komisi', 'heroicon-o-banknotes', 'filament.fo.pages.realisasi-komisi'),
                    ]],
                    ['label' => 'Reward', 'items' => [
                        $item('Daftar Reward', '/fo/rewards', 'heroicon-o-trophy', 'filament.fo.resources.rewards.'),
                        $soon('Pengajuan Reward', 'Komisi', 'Pengajuan Reward', 'heroicon-o-paper-airplane'),
                        $soon('Realisasi Reward', 'Komisi', 'Realisasi Reward', 'heroicon-o-banknotes'),
                    ]],
                ],
            ],
            'report' => [
                'label' => 'Report',
                'icon' => 'heroicon-o-chart-bar',
                'items' => [
                    // Isi laporan menyusul (didefinisikan bertahap). Placeholder agar grup tampil.
                    $soon('Daftar Laporan', 'Report', 'Daftar Laporan', 'heroicon-o-document-chart-bar'),
                ],
            ],
            'pengaturan' => [
                'label' => 'Pengaturan',
                'icon' => 'heroicon-o-cog-6-tooth',
                'items' => [
                    $item('Pengaturan FO', '/fo/pengaturan-fo', 'heroicon-o-cog-6-tooth', 'filament.fo.pages.pengaturan-fo'),
                    $item('Setup Unit — Lokasi', '/fo/lokasis', 'heroicon-o-map-pin', 'filament.fo.resources.lokasis.'),
                    $item('Setup Unit — Tahap', '/fo/tahaps', 'heroicon-o-square-3-stack-3d', 'filament.fo.resources.tahaps.'),
                ],
            ],
            'setup-unit' => [
                'label' => 'Setup Unit',
                'icon' => 'heroicon-o-map-pin',
                'items' => [
                    $item('Lokasi', '/fo/lokasis', 'heroicon-o-map-pin', 'filament.fo.resources.lokasis.'),
                    $item('Tahap', '/fo/tahaps', 'heroicon-o-square-3-stack-3d', 'filament.fo.resources.tahaps.'),
                    $item('Pengaturan FO', '/fo/pengaturan-fo', 'heroicon-o-cog-6-tooth', 'filament.fo.pages.pengaturan-fo'),
                ],
            ],
        ];
    }
}
