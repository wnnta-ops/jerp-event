<?php

declare(strict_types=1);

namespace App\Domain\Finance\VirtualAccount\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;

class AnonymousPayment extends Model
{
    use HasTenant;

    protected $fillable = [
        'tenant_id', 'bank_account_id', 'amount', 'received_at',
        'reference_no', 'description', 'status', 'customer_id', 'tts_receipt_id',
    ];

    protected $casts = ['received_at' => 'datetime', 'amount' => 'decimal:2'];
}
