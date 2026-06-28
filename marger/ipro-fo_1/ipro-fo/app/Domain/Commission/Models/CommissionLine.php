<?php

declare(strict_types=1);

namespace App\Domain\Commission\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommissionLine extends Model
{
    protected $fillable = ['commission_id', 'agent_id', 'tier', 'rate_pct', 'amount', 'trigger_event', 'status'];
    protected $casts    = ['rate_pct' => 'decimal:2', 'amount' => 'decimal:2'];

    public function agent(): BelongsTo { return $this->belongsTo(\App\Domain\Master\Customer\Models\Agent::class); }
}
