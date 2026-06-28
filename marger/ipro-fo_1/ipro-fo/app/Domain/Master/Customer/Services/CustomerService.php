<?php

declare(strict_types=1);

namespace App\Domain\Master\Customer\Services;

use App\Domain\Master\Customer\Models\Customer;
use App\Domain\Master\Customer\Repositories\CustomerRepository;
use App\Exceptions\BusinessRuleException;

class CustomerService
{
    public function __construct(private readonly CustomerRepository $repo) {}

    /**
     * @param array<string, mixed> $filters
     */
    public function paginate(array $filters = [], int $perPage = 50)
    {
        return $this->repo->paginate($filters, $perPage);
    }

    public function ensureIdNumberUnique(string $idNumber, ?int $excludeCustomerId = null): void
    {
        if ($this->repo->existsByIdNumber($idNumber, $excludeCustomerId)) {
            throw new BusinessRuleException(
                'Nomor identitas sudah terdaftar untuk customer lain.',
                errorCode: 'DUPLICATE_ID_NUMBER',
                details: ['id_number' => $idNumber],
            );
        }
    }

    public function ensureCanBeDeleted(Customer $customer): void
    {
        if ($customer->bookings()->whereNotIn('status', ['cancelled', 'draft'])->exists()) {
            throw new BusinessRuleException(
                'Customer memiliki booking aktif dan tidak dapat dihapus. Gunakan status Inactive sebagai gantinya.',
                errorCode: 'CUSTOMER_HAS_ACTIVE_BOOKINGS',
                details: ['customer_id' => $customer->id],
            );
        }
    }
}
