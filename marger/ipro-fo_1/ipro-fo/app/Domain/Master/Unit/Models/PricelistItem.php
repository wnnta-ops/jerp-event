<?php

declare(strict_types=1);

namespace App\Domain\Master\Unit\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PricelistItem extends Model
{
    protected $table = 'pricelist_items';

    protected $fillable = ['pricelist_id', 'unit_id', 'price', 'discount_max_pct'];

    protected $casts = [
        'price'            => 'decimal:2',
        'discount_max_pct' => 'decimal:2',
    ];

    public function pricelist(): BelongsTo
    {
        return $this->belongsTo(Pricelist::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
