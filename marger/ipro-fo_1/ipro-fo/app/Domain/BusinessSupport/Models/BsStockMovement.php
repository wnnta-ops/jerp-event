<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ledger pergerakan stok (Kartu Stok). Dibentuk saat transaksi stok di-post.
 */
class BsStockMovement extends Model
{
    use HasTenant;

    protected $table = 'bs_stock_movements';
    protected $fillable = [
        'tenant_id', 'item_id', 'warehouse_id', 'moved_at', 'direction', 'qty',
        'unit_price', 'balance_after', 'ref_type', 'ref_id', 'ref_number',
    ];
    protected $casts = ['moved_at' => 'date', 'qty' => 'decimal:2', 'unit_price' => 'decimal:2', 'balance_after' => 'decimal:2'];

    public function item(): BelongsTo { return $this->belongsTo(BsItem::class, 'item_id'); }
    public function warehouse(): BelongsTo { return $this->belongsTo(BsWarehouse::class, 'warehouse_id'); }
}
