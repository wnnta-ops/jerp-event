<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BsStockTransactionLine extends Model
{
    protected $table = 'bs_stock_transaction_lines';
    protected $fillable = ['stock_transaction_id', 'item_id', 'qty', 'unit_price'];
    protected $casts = ['qty' => 'decimal:2', 'unit_price' => 'decimal:2'];

    public function item(): BelongsTo { return $this->belongsTo(BsItem::class, 'item_id'); }
}
