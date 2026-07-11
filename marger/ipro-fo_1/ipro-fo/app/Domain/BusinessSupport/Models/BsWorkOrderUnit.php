<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Domain\Master\Unit\Models\Unit;
use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Alokasi unit rumah pada SPK — harga satuan (porsi kontrak) per unit.
 */
class BsWorkOrderUnit extends Model
{
    use HasTenant;

    protected $table = 'bs_work_order_units';

    protected $fillable = ['tenant_id', 'work_order_id', 'unit_id', 'unit_code', 'unit_price', 'seq'];

    protected $casts = ['unit_price' => 'decimal:2', 'seq' => 'integer'];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(BsWorkOrder::class, 'work_order_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }
}
