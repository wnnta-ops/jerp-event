<?php

declare(strict_types=1);

namespace App\Domain\Commission\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommissionPayout extends Model
{
    protected $fillable = ['commission_line_id', 'amount', 'paid_at', 'memo_id', 'status', 'approval_id', 'paid_by'];
    protected $casts    = ['paid_at' => 'date', 'amount' => 'decimal:2'];

    public function line(): BelongsTo { return $this->belongsTo(CommissionLine::class, 'commission_line_id'); }
}
