<?php

declare(strict_types=1);

namespace App\Domain\Collection\Denda\Models;

use App\Domain\Sales\Booking\Models\PaymentSchedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Denda extends Model
{
    protected $table    = 'dendas';
    protected $fillable = [
        'payment_schedule_id', 'computed_date', 'overdue_days', 'rate_per_day',
        'amount', 'amount_waived', 'amount_paid', 'status',
    ];

    protected $casts = [
        'computed_date' => 'date',
        'amount'        => 'decimal:2',
        'amount_waived' => 'decimal:2',
        'amount_paid'   => 'decimal:2',
    ];

    public function paymentSchedule(): BelongsTo
    {
        return $this->belongsTo(PaymentSchedule::class);
    }

    /** Sisa denda yang belum dibayar/diputihkan. */
    public function getOutstandingAttribute(): float
    {
        return round((float) $this->amount - (float) $this->amount_waived - (float) $this->amount_paid, 2);
    }
}
