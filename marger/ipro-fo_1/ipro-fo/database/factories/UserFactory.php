<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Foundation\Tenant\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'tenant_id'         => Tenant::firstOrCreate(['code' => 'MUP'], ['name' => 'PT MUP'])->id,
            'name'              => $this->faker->name(),
            'email'             => $this->faker->unique()->safeEmail(),
            'password'          => Hash::make('Password@123'),
            'is_active'         => true,
            'email_verified_at' => now(),
        ];
    }
}
