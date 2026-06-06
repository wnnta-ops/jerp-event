<?php

namespace App\Modules\FO\MarketingSales\Models;

use App\Modules\FO\Adminjual\Models\Skema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UnitPricelist extends Model
{
    protected $table = 'fo_unit_pricelists';

    protected $fillable = [
        'tenant_id', 'unit_id', 'skema_id', 'no_skema',
        'price_list_minimum', 'price_list_kavling_minimum',
        'price_list', 'price_list_kavling',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'price_list_minimum' => 'decimal:2',
        'price_list_kavling_minimum' => 'decimal:2',
        'price_list' => 'decimal:2',
        'price_list_kavling' => 'decimal:2',
    ];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function skema(): BelongsTo
    {
        return $this->belongsTo(Skema::class);
    }
}
