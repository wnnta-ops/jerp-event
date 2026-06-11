<?php

namespace App\Providers\Filament;

use App\Filament\Fo\Pages\Dashboard;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
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

class FoPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('fo')
            ->path('fo')
            ->brandName('J-ERP · Front Office')
            ->login()
            ->colors([
                'primary' => Color::Blue,
                'gray' => Color::Slate,
                'success' => Color::Emerald,
                'warning' => Color::Amber,
                'danger' => Color::Rose,
                'info' => Color::Sky,
            ])
            ->darkMode()
            ->sidebarCollapsibleOnDesktop()
            ->font('Inter')
            ->discoverResources(in: app_path('Filament/Fo/Resources'), for: 'App\\Filament\\Fo\\Resources')
            ->discoverPages(in: app_path('Filament/Fo/Pages'), for: 'App\\Filament\\Fo\\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Fo/Widgets'), for: 'App\\Filament\\Fo\\Widgets')
            ->widgets([])
            // Replaces auto-discovered nav with module-scoped sidebar:
            // - on /fo (dashboard): no sidebar items (sidebar hidden via CSS)
            // - on /fo/{module-resource-or-page}: only that module's items + back link
            ->navigation(fn (NavigationBuilder $builder) => $this->buildScopedNavigation($builder))
            // Hide the whole sidebar visually on the FO dashboard route only.
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn () => $this->isOnDashboardHome()
                    ? '<style>'
                        .'aside.fi-sidebar, .fi-sidebar-nav, .fi-topbar-open-sidebar-btn, .fi-topbar-close-sidebar-btn { display: none !important; }'
                        .'.fi-main-ctn { padding-inline-start: 0 !important; }'
                        .'.fi-main { max-width: 100% !important; }'
                        .'@media (min-width: 1024px) { .fi-layout { grid-template-columns: 0 1fr !important; } }'
                        .'</style>'
                    : ''
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
            NavigationItem::make('Menu utama (J-ERP)')
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
            'tts' => 'finance',
            'bkms' => 'finance',
            'vas' => 'finance',
            'memos' => 'finance',
            'fps' => 'finance',
            'bgs' => 'finance',
            'refunds' => 'finance',
            'transfer-anonims' => 'finance',
            'reminder-ar' => 'finance',
            // Approval
            'approvals' => 'approval',
            'approval-pengalihan-hak' => 'approval',
            'approval-pindah-unit' => 'approval',
            'approval-pembatalan' => 'approval',
            'approval-diskon' => 'approval',
            'approval-adjustment' => 'approval',
            'approval-reschedule' => 'approval',
            'approval-customize' => 'approval',
            'approval-log' => 'approval',
            // NUP (Setup + Event + Pendaftaran + Verifikasi Data)
            'nup-tipes' => 'nup',
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
        $item = fn (string $label, string $url, string $icon, string $activeRoutePrefix) => NavigationItem::make($label)
            ->url(url($url))
            ->icon($icon)
            ->isActiveWhen(fn () => request()->routeIs($activeRoutePrefix.'*') || request()->is(trim(parse_url($url, PHP_URL_PATH), '/').'*'));

        // Item placeholder: struktur dipasang, flow/logika menyusul. Mengarah ke
        // halaman SegeraHadir dengan label modul+fitur via query string.
        $soon = fn (string $label, string $modul, string $fitur, string $icon = 'heroicon-o-wrench-screwdriver') => NavigationItem::make($label)
            ->url(url('/fo/segera-hadir?modul='.rawurlencode($modul).'&fitur='.rawurlencode($fitur)))
            ->icon($icon)
            ->isActiveWhen(fn () => request()->is('fo/segera-hadir') && request()->query('fitur') === $fitur);

        return [
            'marketing' => [
                'label' => 'Marketing & Sales',
                'icon' => 'heroicon-o-shopping-bag',
                'groups' => [
                    ['label' => 'Customer & Booking', 'items' => [
                        $item('Customer', '/fo/sales', 'heroicon-o-user-group', 'filament.fo.resources.sales.'),
                        $item('Master Booking', '/fo/reservasis', 'heroicon-o-bookmark', 'filament.fo.resources.reservasis.'),
                        $soon('Ganti Nama', 'Marketing & Sales', 'Ganti Nama', 'heroicon-o-arrows-right-left'),
                        $soon('Pindah Unit (Booking)', 'Marketing & Sales', 'Pindah Unit (Booking)', 'heroicon-o-arrow-path-rounded-square'),
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
                    ['label' => 'Migrate', 'items' => [
                        $soon('Upload Kontrak', 'Marketing & Sales', 'Upload Kontrak', 'heroicon-o-arrow-up-tray'),
                        $soon('Upload Jadwal Tagihan', 'Marketing & Sales', 'Upload Jadwal Tagihan', 'heroicon-o-arrow-up-tray'),
                        $soon('Upload Pembayaran', 'Marketing & Sales', 'Upload Pembayaran', 'heroicon-o-arrow-up-tray'),
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
                    $item('TTS', '/fo/tts', 'heroicon-o-ticket', 'filament.fo.resources.tts.'),
                    $item('BKM', '/fo/bkms', 'heroicon-o-banknotes', 'filament.fo.resources.bkms.'),
                    $item('VA', '/fo/vas', 'heroicon-o-credit-card', 'filament.fo.resources.vas.'),
                    $item('MEMO', '/fo/memos', 'heroicon-o-document-currency-dollar', 'filament.fo.resources.memos.'),
                    $item('Faktur Pajak', '/fo/fps', 'heroicon-o-receipt-percent', 'filament.fo.resources.fps.'),
                    $item('Bilyet Giro', '/fo/bgs', 'heroicon-o-document-currency-dollar', 'filament.fo.resources.bgs.'),
                    $item('Refund / CB', '/fo/refunds', 'heroicon-o-arrow-uturn-left', 'filament.fo.resources.refunds.'),
                    $item('Transfer Anonim', '/fo/transfer-anonims', 'heroicon-o-question-mark-circle', 'filament.fo.resources.transfer-anonims.'),
                    $item('Dashboard AR', '/fo/reminder-ar', 'heroicon-o-presentation-chart-line', 'filament.fo.pages.reminder-ar'),
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
                    $item('Approval Adjustment', '/fo/approval-adjustment', 'heroicon-o-adjustments-horizontal', 'filament.fo.resources.approval-adjustment.'),
                    $item('Approval Reschedule', '/fo/approval-reschedule', 'heroicon-o-calendar-days', 'filament.fo.resources.approval-reschedule.'),
                    $item('Approval Customize', '/fo/approval-customize', 'heroicon-o-wrench-screwdriver', 'filament.fo.resources.approval-customize.'),
                    $item('Log File', '/fo/approval-log', 'heroicon-o-document-text', 'filament.fo.pages.approval-log'),
                ],
            ],
            'nup' => [
                'label' => 'NUP',
                'icon' => 'heroicon-o-queue-list',
                'items' => [
                    $item('Setup NUP', '/fo/nup-tipes', 'heroicon-o-adjustments-horizontal', 'filament.fo.resources.nup-tipes.'),
                    $item('Event Launching', '/fo/nup-events', 'heroicon-o-megaphone', 'filament.fo.resources.nup-events.'),
                    $item('Pendaftaran NUP', '/fo/nup-registrations', 'heroicon-o-queue-list', 'filament.fo.resources.nup-registrations.'),
                    $item('Verifikasi Data', '/fo/verifikasi-nups', 'heroicon-o-clipboard-document-check', 'filament.fo.resources.verifikasi-nups.'),
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
                        $soon('Generate BLT', 'Komisi', 'Generate BLT', 'heroicon-o-bolt'),
                        $soon('Pengajuan BLT', 'Komisi', 'Pengajuan BLT', 'heroicon-o-paper-airplane'),
                        $soon('Realisasi BLT', 'Komisi', 'Realisasi BLT', 'heroicon-o-banknotes'),
                    ]],
                    ['label' => 'Komisi', 'items' => [
                        $item('Daftar Komisi', '/fo/komisis', 'heroicon-o-receipt-percent', 'filament.fo.resources.komisis.'),
                        $soon('Generate Komisi', 'Komisi', 'Generate Komisi', 'heroicon-o-bolt'),
                        $soon('Pengajuan Komisi', 'Komisi', 'Pengajuan Komisi', 'heroicon-o-paper-airplane'),
                        $soon('Realisasi Komisi', 'Komisi', 'Realisasi Komisi', 'heroicon-o-banknotes'),
                    ]],
                    ['label' => 'Reward', 'items' => [
                        $item('Daftar Reward', '/fo/rewards', 'heroicon-o-trophy', 'filament.fo.resources.rewards.'),
                        $soon('Pengajuan Reward', 'Komisi', 'Pengajuan Reward', 'heroicon-o-paper-airplane'),
                        $soon('Realisasi Reward', 'Komisi', 'Realisasi Reward', 'heroicon-o-banknotes'),
                    ]],
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
