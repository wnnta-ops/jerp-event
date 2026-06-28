<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Master\Customer\Models\Customer;
use App\Domain\Master\Customer\Policies\CustomerPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Customer::class => CustomerPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();

        // SuperAdmin bypass: kalau punya role SuperAdmin, semua gate diizinkan.
        Gate::before(fn ($user, $ability) => $user->hasRole('SuperAdmin') ? true : null);
    }
}
