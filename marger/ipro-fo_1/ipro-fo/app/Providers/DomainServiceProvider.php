<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Foundation\Numerator\Services\NumeratorService;
use App\Domain\Master\Customer\Repositories\CustomerRepository;
use Illuminate\Support\ServiceProvider;

class DomainServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Foundation
        $this->app->singleton(NumeratorService::class);

        // Master Customer
        $this->app->singleton(CustomerRepository::class);
    }

    public function boot(): void
    {
        //
    }
}
