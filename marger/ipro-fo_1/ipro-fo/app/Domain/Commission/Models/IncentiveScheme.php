<?php

declare(strict_types=1);

namespace App\Domain\Commission\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Skema insentif (Setup) per jenis BLT / Reward / Komisi.
 */
class IncentiveScheme extends Model
{
    use HasTenant, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'type', 'code', 'name', 'basis', 'value', 'unit_type_id', 'is_active', 'notes',
    ];

    protected $casts = [
        'value'     => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public const TYPES = ['blt' => 'BLT', 'reward' => 'Reward', 'komisi' => 'Komisi'];
}
