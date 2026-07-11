<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Baris unit hasil import Excel pada modul Generate BAST (Contractor Management).
 */
class BsBastUnit extends Model
{
    use HasTenant;

    protected $table = 'bs_bast_units';

    protected $fillable = [
        'tenant_id', 'project', 'unit_no', 'location', 'phase', 'block',
        'number', 'type', 'cluster', 'lt', 'lb', 'price', 'unit_status', 'created_by',
    ];

    protected $casts = [
        'lt'    => 'integer',
        'lb'    => 'integer',
        'price' => 'decimal:2',
    ];
}
