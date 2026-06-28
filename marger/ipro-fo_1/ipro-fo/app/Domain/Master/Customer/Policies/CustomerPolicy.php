<?php

declare(strict_types=1);

namespace App\Domain\Master\Customer\Policies;

use App\Domain\Master\Customer\Models\Customer;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class CustomerPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('master.customer.view');
    }

    public function view(User $user, Customer $customer): bool
    {
        return $user->can('master.customer.view') && $this->sameTenant($user, $customer);
    }

    public function create(User $user): bool
    {
        return $user->can('master.customer.create');
    }

    public function update(User $user, Customer $customer): bool
    {
        return $user->can('master.customer.update') && $this->sameTenant($user, $customer);
    }

    public function delete(User $user, Customer $customer): bool
    {
        return $user->can('master.customer.delete') && $this->sameTenant($user, $customer);
    }

    public function viewSensitive(User $user, Customer $customer): bool
    {
        return $user->can('master.customer.view.sensitive') && $this->sameTenant($user, $customer);
    }

    public function anonymize(User $user, Customer $customer): bool
    {
        return $user->can('master.customer.anonymize') && $this->sameTenant($user, $customer);
    }

    private function sameTenant(User $user, Customer $customer): bool
    {
        return $user->tenant_id === $customer->tenant_id;
    }
}
