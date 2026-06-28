<?php

declare(strict_types=1);

namespace App\Domain\Commission\Models;

use Illuminate\Database\Eloquent\Model;

class CommissionSchemeTerm extends Model
{
    protected $fillable = ['commission_scheme_id', 'agent_type', 'trigger_event', 'rate_pct', 'flat_amount', 'term_no'];
    protected $casts    = ['rate_pct' => 'decimal:2', 'flat_amount' => 'decimal:2'];
}
