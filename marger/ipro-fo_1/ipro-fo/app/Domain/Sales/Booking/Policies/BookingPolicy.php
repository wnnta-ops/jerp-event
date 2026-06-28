<?php

declare(strict_types=1);

namespace App\Domain\Sales\Booking\Policies;

use App\Domain\Sales\Booking\Models\Booking;
use App\Models\User;

class BookingPolicy
{
    public function viewAny(User $user): bool   { return $user->can('sales.booking.view'); }
    public function view(User $user, Booking $b): bool   { return $user->can('sales.booking.view') && $user->tenant_id === $b->tenant_id; }
    public function create(User $user): bool    { return $user->can('sales.booking.create'); }
    public function update(User $user, Booking $b): bool { return $user->can('sales.booking.update') && $user->tenant_id === $b->tenant_id; }
    public function cancel(User $user, Booking $b): bool { return $user->can('sales.booking.cancel') && $user->tenant_id === $b->tenant_id; }
    public function promote(User $user, Booking $b): bool{ return $user->can('sales.booking.promote') && $user->tenant_id === $b->tenant_id; }
}
