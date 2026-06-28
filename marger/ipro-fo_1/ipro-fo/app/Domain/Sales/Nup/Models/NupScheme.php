<?php

declare(strict_types=1);

namespace App\Domain\Sales\Nup\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NupScheme extends Model
{
    use HasFactory, HasTenant;

    protected $fillable = [
        'tenant_id', 'project_id', 'launching_id', 'code', 'name',
        'nup_amount', 'open_from', 'open_to', 'is_refundable', 'refund_penalty_pct', 'is_active',
    ];

    protected $casts = [
        'open_from'         => 'date',
        'open_to'           => 'date',
        'nup_amount'        => 'decimal:2',
        'refund_penalty_pct'=> 'decimal:2',
        'is_refundable'     => 'boolean',
        'is_active'         => 'boolean',
    ];
}
