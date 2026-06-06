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
            // Marketing & Sales
            'customers' => 'marketing',
            'reservasis' => 'marketing',
            'kontraks' => 'marketing',
            // Setup Sales (master + sales + unit + gimmick)
            'skemas' => 'setup',
            'sales-types' => 'setup',
            'sales-levels' => 'setup',
            'agents' => 'setup',
            'price-lists' => 'setup',
            'gimmicks' => 'setup',
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
            // Approval
            'approvals' => 'approval',
            // NUP (Setup + Event + Pendaftaran + Verifikasi Data)
            'nup-tipes' => 'nup',
            'nup-events' => 'nup',
            'nup-registrations' => 'nup',
            'verifikasi-nups' => 'nup',
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
            // Pengaturan + Setup Unit (master Lokasi & Tahap)
            'pengaturan-fo' => 'pengaturan',
            'lokasis' => 'setup-unit',
            'tahaps' => 'setup-unit',
        ];

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

        return [
            'marketing' => [
                'label' => 'Marketing & Sales',
                'icon' => 'heroicon-o-shopping-bag',
                'items' => [
                    $item('Customer', '/fo/customers', 'heroicon-o-user-group', 'filament.fo.resources.customers.'),
                    $item('Reservasi', '/fo/reservasis', 'heroicon-o-bookmark', 'filament.fo.resources.reservasis.'),
                    $item('Kontrak', '/fo/kontraks', 'heroicon-o-document-text', 'filament.fo.resources.kontraks.'),
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
                    $item('Gimmick', '/fo/gimmicks', 'heroicon-o-sparkles', 'filament.fo.resources.gimmicks.'),
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
                ],
            ],
            'approval' => [
                'label' => 'Approval',
                'icon' => 'heroicon-o-clipboard-document-check',
                'items' => [
                    $item('Approval', '/fo/approvals', 'heroicon-o-clipboard-document-check', 'filament.fo.resources.approvals.'),
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
                'items' => [
                    $item('Sales (master)', '/fo/agents', 'heroicon-o-user-circle', 'filament.fo.resources.agents.'),
                    $item('Komisi', '/fo/komisis', 'heroicon-o-receipt-percent', 'filament.fo.resources.komisis.'),
                    $item('Reward', '/fo/rewards', 'heroicon-o-trophy', 'filament.fo.resources.rewards.'),
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
