<?php

declare(strict_types=1);

namespace App\Domain\Finance\VirtualAccount\Models;

use Illuminate\Database\Eloquent\Model;

class VaTransaction extends Model
{
    protected $fillable = ['virtual_account_id', 'amount', 'received_at', 'external_reference', 'status', 'tts_receipt_id'];
    protected $casts    = ['received_at' => 'datetime', 'amount' => 'decimal:2'];
}
