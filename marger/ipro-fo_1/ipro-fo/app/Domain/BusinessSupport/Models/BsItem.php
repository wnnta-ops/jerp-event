<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class BsItem extends Model
{
    use HasTenant, SoftDeletes;

    protected $table = 'bs_items';
    protected $fillable = ['tenant_id', 'stock_category_id', 'uom_id', 'code', 'name', 'brand', 'type', 'min_stock', 'is_active'];
    protected $casts = ['is_active' => 'boolean', 'min_stock' => 'decimal:2'];

    public const TYPES = ['stock' => 'Stok', 'non_stock' => 'Non-Stok', 'jasa' => 'Jasa'];

    public function category(): BelongsTo { return $this->belongsTo(BsStockCategory::class, 'stock_category_id'); }
    public function uom(): BelongsTo { return $this->belongsTo(BsUom::class, 'uom_id'); }

    /** Stok on-hand total (semua gudang) dari ledger pergerakan. */
    public function onHand(): float
    {
        return (float) BsStockMovement::where('item_id', $this->id)
            ->selectRaw("COALESCE(SUM(CASE WHEN direction='in' THEN qty ELSE -qty END),0) as bal")
            ->value('bal');
    }
}
