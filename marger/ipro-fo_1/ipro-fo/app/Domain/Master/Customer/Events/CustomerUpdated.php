<?php

declare(strict_types=1);

namespace App\Domain\Master\Customer\Events;

use App\Domain\Master\Customer\Models\Customer;
use Illuminate\Foundation\Events\Dispatchable;

final class CustomerUpdated
{
    use Dispatchable;

    public function __construct(public readonly Customer $customer) {}
}
