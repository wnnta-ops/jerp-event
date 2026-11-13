<?php

use App\Modules\BankLoan\Providers\BankLoanServiceProvider;
use App\Modules\BO\Providers\BoServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\Filament\BackupPanelProvider;
use App\Providers\Filament\EventPanelProvider;
use App\Providers\Filament\FoPanelProvider;
use App\Providers\Filament\SystemPanelProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\ModuleServiceProvider;

return [
    AppServiceProvider::class,
    ModuleServiceProvider::class,
    // BO + Bankloan kini di ipro-fo (akses via SSO dari hub). Panel LAMA J-ERP
    // dipensiunkan; ServiceProvider tetap (berisi bridge FOBO + sync ke ipro_bo).
    BoServiceProvider::class,
    BankLoanServiceProvider::class,
    FoPanelProvider::class,
    BackupPanelProvider::class,
    SystemPanelProvider::class,
    EventPanelProvider::class,
    HorizonServiceProvider::class,
];
