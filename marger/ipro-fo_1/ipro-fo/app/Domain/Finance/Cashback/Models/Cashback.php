<?php

declare(strict_types=1);

namespace App\Domain\Finance\Cashback\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Cashback extends Model
{
    use HasTenant;

    protected $fillable = ['tenant_id', 'booking_id', 'amount', 'reason', 'status', 'approval_id', 'paid_at'];
    protected $casts    = ['paid_at' => 'date', 'amount' => 'decimal:2'];

    public function booking(): BelongsTo { return $this->belongsTo(\App\Domain\Sales\Booking\Models\Booking::class); }
}
