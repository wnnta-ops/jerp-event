<?php

declare(strict_types=1);

namespace App\Domain\Master\Customer\Actions;

use App\Domain\Master\Customer\DTOs\CustomerDto;
use App\Domain\Master\Customer\Events\CustomerUpdated;
use App\Domain\Master\Customer\Models\Customer;
use App\Domain\Master\Customer\Services\CustomerService;
use Illuminate\Support\Facades\DB;

final class UpdateCustomer
{
    public function __construct(private readonly CustomerService $service) {}

    /**
     * @param array<string, mixed> $partial Field yang dipost (partial update).
     */
    public function execute(Customer $customer, array $partial): Customer
    {
        return DB::transaction(function () use ($customer, $partial): Customer {
            if (isset($partial['id_number']) && $partial['id_number'] !== $customer->id_number) {
                $this->service->ensureIdNumberUnique($partial['id_number'], $customer->id);
            }

            $partial['updated_by'] = auth()->id();
            $customer->update($partial);

            event(new CustomerUpdated($customer));

            return $customer->refresh();
        });
    }
}
