<?php

declare(strict_types=1);

namespace App\Domain\Master\Customer\Repositories;

use App\Domain\Master\Customer\Models\Customer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class CustomerRepository
{
    /**
     * @param array<string, mixed> $filters
     */
    public function paginate(array $filters = [], int $perPage = 50): LengthAwarePaginator
    {
        return $this->buildQuery($filters)
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return Builder<Customer>
     */
    public function buildQuery(array $filters = []): Builder
    {
        return Customer::query()
            ->when($filters['search']    ?? null, fn ($q, $term) => $q->where(function (Builder $q) use ($term): void {
                $q->where('full_name', 'like', "%{$term}%")
                  ->orWhere('code', 'like', "%{$term}%")
                  ->orWhere('phone', 'like', "%{$term}%")
                  ->orWhere('email', 'like', "%{$term}%");
            }))
            ->when($filters['status']    ?? null, fn ($q, $value) => $q->where('status', $value))
            ->when($filters['id_type']   ?? null, fn ($q, $value) => $q->where('id_type', $value))
            ->when($filters['channel']   ?? null, fn ($q, $value) => $q->where('channel', $value))
            ->when($filters['city']      ?? null, fn ($q, $value) => $q->where('city', $value));
    }

    public function findByPhoneOrEmail(?string $phone, ?string $email): ?Customer
    {
        if (! $phone && ! $email) {
            return null;
        }

        return Customer::query()
            ->when($phone, fn ($q) => $q->orWhere('phone', $phone))
            ->when($email, fn ($q) => $q->orWhere('email', $email))
            ->first();
    }

    public function existsByIdNumber(string $idNumber, ?int $excludeId = null): bool
    {
        // Cek decrypted value satu-per-satu karena id_number ter-enkripsi.
        return Customer::query()
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->cursor()
            ->contains(fn (Customer $c) => $c->id_number === $idNumber);
    }
}
