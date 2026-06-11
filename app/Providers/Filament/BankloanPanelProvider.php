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

class BankloanPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('bankloan')
            ->path('bankloan')
            ->brandName('J-ERP · Bank Loan (KPR)')
            ->login()
            ->colors([
                'primary' => Color::Teal,
                'gray' => Color::Slate,
                'success' => Color::Emerald,
                'warning' => Color::Amber,
                'danger' => Color::Rose,
                'info' => Color::Sky,
            ])
            ->darkMode()
            ->sidebarCollapsibleOnDesktop()
            ->font('Inter')
            ->discoverResources(in: app_path('Filament/Bankloan/Resources'), for: 'App\\Filament\\Bankloan\\Resources')
            ->discoverPages(in: app_path('Filament/Bankloan/Pages'), for: 'App\\Filament\\Bankloan\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Bankloan/Widgets'), for: 'App\\Filament\\Bankloan\\Widgets')
            ->widgets([])
            ->navigationGroups([
                'Kontrak KPR',
                'Realisasi & Retensi',
                'Meeting',
                'Master Data',
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
