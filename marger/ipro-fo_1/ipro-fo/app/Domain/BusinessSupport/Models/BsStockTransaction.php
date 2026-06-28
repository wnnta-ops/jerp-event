<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BsStockTransaction extends Model
{
    use HasTenant, SoftDeletes;

    protected $table = 'bs_stock_transactions';
    protected $fillable = [
        'tenant_id', 'trx_number', 'trx_type', 'trx_date', 'warehouse_id', 'supplier_id',
        'purchase_order_id', 'material_request_id', 'reference', 'notes', 'created_by',
    ];
    protected $casts = ['trx_date' => 'date'];

    public function lines(): HasMany { return $this->hasMany(BsStockTransactionLine::class, 'stock_transaction_id'); }
    public function warehouse(): BelongsTo { return $this->belongsTo(BsWarehouse::class, 'warehouse_id'); }
    public function supplier(): BelongsTo { return $this->belongsTo(BsSupplier::class, 'supplier_id'); }
    public function purchaseOrder(): BelongsTo { return $this->belongsTo(BsPurchaseOrder::class, 'purchase_order_id'); }
}
