<?php

namespace App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Mounts per-module route files at /{module} with web + auth middleware.
 *
 * BO, Backup, System are owned by Filament panels (registered separately)
 * so they are NOT mounted here — only FO and BankLoan use Blade/Livewire.
 */
class ModuleServiceProvider extends ServiceProvider
{
    /** @var array<int,string> */
    protected array $modules = ['bankloan'];

    public function boot(): void
    {
        foreach ($this->modules as $module) {
            $path = base_path("routes/modules/{$module}.php");

            if (! file_exists($path)) {
                continue;
            }

            Route::middleware(['web', 'auth'])
                ->prefix($module)
                ->name($module.'.')
                ->group($path);
        }
    }
}
