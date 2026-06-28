<?php

declare(strict_types=1);

namespace App\Domain\Foundation\Numerator\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Numerator extends Model
{
    use HasFactory, HasTenant;

    protected $fillable = [
        'tenant_id', 'project_id', 'code', 'prefix', 'format',
        'seq_padding', 'reset_strategy', 'current_period', 'current_seq',
    ];
}
