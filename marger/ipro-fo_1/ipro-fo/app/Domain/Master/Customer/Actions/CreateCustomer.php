<?php

declare(strict_types=1);

namespace App\Domain\Master\Customer\Actions;

use App\Domain\Foundation\Numerator\Services\NumeratorService;
use App\Domain\Master\Customer\DTOs\CustomerDto;
use App\Domain\Master\Customer\Enums\CustomerStatus;
use App\Domain\Master\Customer\Events\CustomerCreated;
use App\Domain\Master\Customer\Models\Customer;
use App\Domain\Master\Customer\Services\CustomerService;
use Illuminate\Support\Facades\DB;

final class CreateCustomer
{
    public function __construct(
        private readonly NumeratorService $numerator,
        private readonly CustomerService $service,
    ) {}

    /**
     * @param CustomerDto|array<string, mixed> $data DTO atau array dari Request validated.
     */
    public function execute(CustomerDto|array $data): Customer
    {
        $payload = $data instanceof CustomerDto ? $data->toArray() : $data;

        return DB::transaction(function () use ($payload): Customer {
            $idNumber = $payload['id_number'] ?? '';
            if ($idNumber !== '') {
                $this->service->ensureIdNumberUnique($idNumber);
            }

            $code = $this->numerator->next('CUST');

            $customer = Customer::create([
                ...$payload,
                'code'       => $code,
                'status'     => CustomerStatus::Prospect,
                'created_by' => auth()->id(),
            ]);

            event(new CustomerCreated($customer));

            return $customer;
        });
    }
}
