<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationItem;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class BoPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('bo')
            ->path('bo')
            ->brandName('Java Core · Back Office')
            ->favicon(asset('images/logo/logo-daun.png'))
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
            ->discoverResources(in: app_path('Filament/Bo/Resources'), for: 'App\\Filament\\Bo\\Resources')
            ->discoverResources(in: app_path('Filament/Bo/Clusters/GeneralLedger/Resources'), for: 'App\\Filament\\Bo\\Clusters\\GeneralLedger\\Resources')
            ->discoverPages(in: app_path('Filament/Bo/Pages'), for: 'App\\Filament\\Bo\\Pages')
            ->discoverPages(in: app_path('Filament/Bo/Clusters/GeneralLedger/Pages'), for: 'App\\Filament\\Bo\\Clusters\\GeneralLedger\\Pages')
            ->discoverClusters(in: app_path('Filament/Bo/Clusters'), for: 'App\\Filament\\Bo\\Clusters')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Bo/Widgets'), for: 'App\\Filament\\Bo\\Widgets')
            ->widgets([])
            ->navigationGroups([
                'General Ledger',
                'Cash & Bank',
                'Piutang (AR)',
                'Hutang (AP)',
                'Inventory',
                'Purchasing',
                'HR',
                'Laporan',
                'FOBO Bridge',
                'Setup',
            ])
            ->navigationItems([
                NavigationItem::make('menu-utama')
                    ->label('Menu utama')
                    ->url(fn () => url('/dashboard'))
                    ->icon('heroicon-o-arrow-left-circle')
                    ->sort(-1),
            ])
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
}
