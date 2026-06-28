<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BsMaterialRequestLine extends Model
{
    protected $table = 'bs_material_request_lines';
    protected $fillable = ['material_request_id', 'item_id', 'qty', 'kind', 'spec'];
    protected $casts = ['qty' => 'decimal:2'];

    public function item(): BelongsTo { return $this->belongsTo(BsItem::class, 'item_id'); }
}
