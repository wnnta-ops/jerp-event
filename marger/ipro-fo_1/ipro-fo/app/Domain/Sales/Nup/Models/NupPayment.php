<?php

declare(strict_types=1);

namespace App\Domain\Sales\Nup\Models;

use Illuminate\Database\Eloquent\Model;

class NupPayment extends Model
{
    protected $fillable = [
        'nup_registration_id', 'amount', 'paid_at',
        'payment_method', 'reference_no', 'received_by',
    ];

    protected $casts = [
        'paid_at' => 'date',
        'amount'  => 'decimal:2',
    ];
}
