<?php

use App\Modules\BankLoan\Providers\BankLoanServiceProvider;
use App\Modules\BO\Providers\BoServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\Filament\BackupPanelProvider;
use App\Providers\Filament\BankloanPanelProvider;
use App\Providers\Filament\BoPanelProvider;
use App\Providers\Filament\FoPanelProvider;
use App\Providers\Filament\SystemPanelProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\ModuleServiceProvider;

return [
    AppServiceProvider::class,
    ModuleServiceProvider::class,
    BoServiceProvider::class,
    BankLoanServiceProvider::class,
    FoPanelProvider::class,
    BoPanelProvider::class,
    BankloanPanelProvider::class,
    BackupPanelProvider::class,
    SystemPanelProvider::class,
    HorizonServiceProvider::class,
];
