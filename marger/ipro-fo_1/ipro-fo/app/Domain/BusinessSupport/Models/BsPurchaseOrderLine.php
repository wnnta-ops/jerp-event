<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BsPurchaseOrderLine extends Model
{
    protected $table = 'bs_purchase_order_lines';
    protected $fillable = ['purchase_order_id', 'item_id', 'qty', 'qty_received', 'unit_price', 'amount'];
    protected $casts = ['qty' => 'decimal:2', 'qty_received' => 'decimal:2', 'unit_price' => 'decimal:2', 'amount' => 'decimal:2'];

    public function item(): BelongsTo { return $this->belongsTo(BsItem::class, 'item_id'); }
    public function order(): BelongsTo { return $this->belongsTo(BsPurchaseOrder::class, 'purchase_order_id'); }
}
