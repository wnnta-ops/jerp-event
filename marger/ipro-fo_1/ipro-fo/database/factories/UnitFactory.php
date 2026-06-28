<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Master\Unit\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Unit>
 */
class UnitFactory extends Factory
{
    protected $model = Unit::class;

    public function definition(): array
    {
        return [
            'tenant_id'     => 1,
            'project_id'    => 1,
            'unit_code'     => 'U-'.$this->faker->unique()->numerify('####'),
            'base_price'    => 850_000_000,
            'current_price' => 850_000_000,
            'status'        => 'available',
        ];
    }

    public function booked(): self  { return $this->state(['status' => 'booked']); }
    public function sold(): self    { return $this->state(['status' => 'sold']); }
    public function hold(): self    { return $this->state(['status' => 'hold']); }
}
