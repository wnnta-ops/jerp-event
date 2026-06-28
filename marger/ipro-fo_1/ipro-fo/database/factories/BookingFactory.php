<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Sales\Booking\Models\Booking;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Booking>
 */
class BookingFactory extends Factory
{
    protected $model = Booking::class;

    public function definition(): array
    {
        return [
            'tenant_id'         => 1,
            'project_id'        => 1,
            'booking_number'    => 'BK/JRES/'.now()->format('ym').'/'.$this->faker->unique()->numerify('#####'),
            'customer_id'       => \App\Domain\Master\Customer\Models\Customer::factory(),
            'unit_id'           => 1,
            'payment_scheme_id' => 1,
            'booking_date'      => now()->toDateString(),
            'base_price'        => 850_000_000,
            'discount_amount'   => 0,
            'discount_pct'      => 0,
            'ppn_pct'           => 11,
            'ppn_amount'        => 93_500_000,
            'total_price'       => 943_500_000,
            'status'            => 'draft',
            'created_by'        => 1,
        ];
    }
}
