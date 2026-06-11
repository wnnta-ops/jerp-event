<?php

namespace App\Providers\Filament;

use App\Filament\Backup\Widgets\BackupStats;
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

class BackupPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('backup')
            ->path('backup')
            ->brandName('J-ERP · Backup')
            ->login()
            ->colors([
                'primary' => Color::Cyan,
                'gray' => Color::Slate,
                'success' => Color::Emerald,
                'warning' => Color::Amber,
                'danger' => Color::Rose,
                'info' => Color::Sky,
            ])
            ->darkMode()
            ->sidebarCollapsibleOnDesktop()
            ->font('Inter')
            ->discoverResources(in: app_path('Filament/Backup/Resources'), for: 'App\\Filament\\Backup\\Resources')
            ->discoverPages(in: app_path('Filament/Backup/Pages'), for: 'App\\Filament\\Backup\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Backup/Widgets'), for: 'App\\Filament\\Backup\\Widgets')
            ->widgets([
                BackupStats::class,
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
