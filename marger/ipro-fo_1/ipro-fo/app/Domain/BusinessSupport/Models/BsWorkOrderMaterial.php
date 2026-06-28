<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BsWorkOrderMaterial extends Model
{
    use HasTenant;

    protected $table = 'bs_work_order_materials';
    protected $fillable = [
        'tenant_id', 'work_order_id', 'usage_date', 'description', 'amount', 'inventory_account_id', 'journal_id', 'created_by',
        'status', 'closed_at', 'returned_amount',
    ];
    protected $casts = ['usage_date' => 'date', 'closed_at' => 'date', 'amount' => 'decimal:2', 'returned_amount' => 'decimal:2'];

    public function workOrder(): BelongsTo { return $this->belongsTo(BsWorkOrder::class, 'work_order_id'); }
    public function inventoryAccount(): BelongsTo { return $this->belongsTo(BsAccount::class, 'inventory_account_id'); }
}
