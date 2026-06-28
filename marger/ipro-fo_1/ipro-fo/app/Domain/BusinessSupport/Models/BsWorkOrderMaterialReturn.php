<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pengembalian Sisa Pemakaian (retur material SBO). */
class BsWorkOrderMaterialReturn extends Model
{
    use HasTenant;

    protected $table = 'bs_work_order_material_returns';
    protected $fillable = ['tenant_id', 'work_order_id', 'material_id', 'return_date', 'description', 'amount', 'inventory_account_id', 'journal_id', 'created_by'];
    protected $casts = ['return_date' => 'date', 'amount' => 'decimal:2'];

    public function workOrder(): BelongsTo { return $this->belongsTo(BsWorkOrder::class, 'work_order_id'); }
    public function material(): BelongsTo { return $this->belongsTo(BsWorkOrderMaterial::class, 'material_id'); }
    public function inventoryAccount(): BelongsTo { return $this->belongsTo(BsAccount::class, 'inventory_account_id'); }
}
