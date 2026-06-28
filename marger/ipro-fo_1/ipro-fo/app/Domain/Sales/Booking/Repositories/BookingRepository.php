<?php

declare(strict_types=1);

namespace App\Domain\Sales\Booking\Repositories;

use App\Domain\Sales\Booking\Models\Booking;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class BookingRepository
{
    public function paginate(array $filters = [], int $perPage = 50): LengthAwarePaginator
    {
        return $this->buildQuery($filters)
            ->with([
                'customer:id,code,full_name,phone',
                'unit:id,unit_code,current_price',
                'paymentScheme:id,code,name',
            ])
            ->latest('booking_date')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @return Builder<Booking>
     */
    public function buildQuery(array $filters = []): Builder
    {
        return Booking::query()
            ->when($filters['search']        ?? null, fn ($q, $term)  => $q->where('booking_number', 'like', "%{$term}%"))
            ->when($filters['status']        ?? null, fn ($q, $value) => $q->where('status', $value))
            ->when($filters['customer_id']   ?? null, fn ($q, $value) => $q->where('customer_id', $value))
            ->when($filters['project_id']    ?? null, fn ($q, $value) => $q->where('project_id', $value))
            ->when($filters['date_from']     ?? null, fn ($q, $value) => $q->whereDate('booking_date', '>=', $value))
            ->when($filters['date_to']       ?? null, fn ($q, $value) => $q->whereDate('booking_date', '<=', $value));
    }
}
