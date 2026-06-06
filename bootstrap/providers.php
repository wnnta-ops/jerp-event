<?php

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\ModuleServiceProvider::class,
    App\Modules\BO\Providers\BoServiceProvider::class,
    App\Providers\Filament\FoPanelProvider::class,
    App\Providers\Filament\BoPanelProvider::class,
    App\Providers\Filament\BackupPanelProvider::class,
    App\Providers\Filament\SystemPanelProvider::class,
    App\Providers\HorizonServiceProvider::class,
];
