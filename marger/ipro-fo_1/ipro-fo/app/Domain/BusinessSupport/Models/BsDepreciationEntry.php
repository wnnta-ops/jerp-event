<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BsDepreciationEntry extends Model
{
    protected $table = 'bs_depreciation_entries';
    protected $fillable = ['fixed_asset_id', 'period_year', 'period_month', 'entry_date', 'amount', 'book_value_after', 'journal_id'];
    protected $casts = [
        'entry_date' => 'date', 'amount' => 'decimal:2', 'book_value_after' => 'decimal:2',
        'period_year' => 'integer', 'period_month' => 'integer',
    ];

    public function asset(): BelongsTo { return $this->belongsTo(BsFixedAsset::class, 'fixed_asset_id'); }
}
