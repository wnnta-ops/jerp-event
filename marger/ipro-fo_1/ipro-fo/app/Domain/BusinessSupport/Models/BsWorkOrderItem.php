<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Detil Pekerjaan SPK (BoQ/RAB). */
class BsWorkOrderItem extends Model
{
    use HasTenant;

    protected $table = 'bs_work_order_items';
    protected $fillable = ['tenant_id', 'work_order_id', 'seq', 'description', 'unit', 'qty', 'unit_price', 'amount'];
    protected $casts = ['seq' => 'integer', 'qty' => 'decimal:2', 'unit_price' => 'decimal:2', 'amount' => 'decimal:2'];

    public function workOrder(): BelongsTo { return $this->belongsTo(BsWorkOrder::class, 'work_order_id'); }
}
