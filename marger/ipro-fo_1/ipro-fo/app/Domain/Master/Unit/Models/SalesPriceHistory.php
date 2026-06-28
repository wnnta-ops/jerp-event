<?php

declare(strict_types=1);

namespace App\Domain\Master\Unit\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * History Price List — log perubahan harga unit (eProperty: Setup Sales → History Price List).
 */
class SalesPriceHistory extends Model
{
    use HasTenant;

    protected $table = 'sales_price_histories';

    protected $fillable = [
        'tenant_id', 'unit_id', 'pricelist_id', 'old_price', 'new_price', 'source', 'note', 'changed_by',
    ];

    protected $casts = [
        'old_price' => 'decimal:2',
        'new_price' => 'decimal:2',
    ];

    public const SOURCES = ['manual' => 'Manual', 'pricelist' => 'Aktivasi Price List', 'import' => 'Import'];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
