<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Foundation\Tenant\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        return [
            'code'         => strtoupper($this->faker->unique()->lexify('TEN???')),
            'name'         => $this->faker->company(),
            'activated_at' => now(),
        ];
    }
}
