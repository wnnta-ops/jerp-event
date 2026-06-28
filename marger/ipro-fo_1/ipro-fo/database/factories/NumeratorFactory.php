<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Foundation\Numerator\Models\Numerator;
use App\Domain\Foundation\Tenant\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Numerator>
 */
class NumeratorFactory extends Factory
{
    protected $model = Numerator::class;

    public function definition(): array
    {
        return [
            'tenant_id'      => Tenant::firstOrCreate(['code' => 'MUP'], ['name' => 'PT MUP'])->id,
            'project_id'     => null,
            'code'           => 'TEST',
            'prefix'         => 'TST',
            'format'         => '{PREFIX}-{YYYY}-{SEQ}',
            'seq_padding'    => 5,
            'reset_strategy' => 'yearly',
            'current_seq'    => 0,
        ];
    }
}
