<?php

declare(strict_types=1);

namespace App\Domain\Sales\Pricing\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentSchemeTerm extends Model
{
    protected $fillable = [
        'payment_scheme_id', 'term_no', 'label', 'basis', 'value', 'due_after_days', 'is_dp',
    ];

    protected $casts = [
        'value'  => 'decimal:2',
        'is_dp'  => 'boolean',
    ];
}
