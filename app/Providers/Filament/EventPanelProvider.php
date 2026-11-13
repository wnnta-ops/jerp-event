<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Panel Event — setup event, live board, laporan. Gerbang panel lewat
 * User::canAccessPanel → config('akses.panel.event'), sama seperti panel lain.
 * Scanner HP (/event/scan) sengaja di luar panel: dijaga login + et_access.
 */
class EventPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('event')
            ->path('event')
            ->brandName('Java Core · Event')
            ->favicon(asset('images/logo/logo-daun.png'))
            ->login()
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
            ->discoverResources(in: app_path('Filament/Event/Resources'), for: 'App\\Filament\\Event\\Resources')
            ->discoverPages(in: app_path('Filament/Event/Pages'), for: 'App\\Filament\\Event\\Pages')
            ->navigationItems([
                NavigationItem::make('menu-utama')
                    ->label('Menu utama')
                    ->url(fn () => url('/dashboard'))
                    ->icon('heroicon-o-arrow-left-circle')
                    // Panel ini tak punya halaman dashboard: Filament mengarahkan /event ke menu
                    // PERTAMA. Kalau "Menu utama" di atas, /event memantul balik ke /dashboard.
                    ->sort(100),
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
