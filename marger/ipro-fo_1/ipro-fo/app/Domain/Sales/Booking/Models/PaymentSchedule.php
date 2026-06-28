<?php

declare(strict_types=1);

namespace App\Domain\Sales\Booking\Models;

use App\Domain\Finance\Payment\Enums\PaymentType;
use App\Domain\Finance\Payment\Models\PaymentAllocation;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentSchedule extends Model
{
    use HasFactory;

    protected $fillable = [
        'booking_id', 'installment_no', 'sequence', 'payment_type',
        'label', 'due_date', 'amount', 'paid_amount', 'status', 'paid_at',
    ];

    protected $casts = [
        'due_date'      => 'date',
        'paid_at'       => 'datetime',
        'amount'        => 'decimal:2',
        'paid_amount'   => 'decimal:2',
        'payment_type'  => PaymentType::class,
        'sequence'      => 'integer',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class, 'payment_schedule_id');
    }

    public function getOutstandingAttribute(): float
    {
        return (float) $this->amount - (float) $this->paid_amount;
    }

    /**
     * Compute "schedule number" yang ditampilkan di UI: NoBooking.{sequence}
     */
    public function getDisplayNumberAttribute(): string
    {
        $base = $this->booking?->booking_number ?? '?';
        $seq  = $this->sequence ?? $this->installment_no ?? '?';

        return "{$base}.{$seq}";
    }

    /**
     * Recompute paid_amount from allocations (active ones).
     */
    public function recomputePaid(): void
    {
        $sum = (float) $this->allocations()->sum('amount');
        $this->paid_amount = $sum;
        $this->status      = $sum >= (float) $this->amount ? 'paid' : ($sum > 0 ? 'partial' : 'unpaid');
        $this->paid_at     = $this->status === 'paid' ? ($this->paid_at ?? now()) : null;
        $this->save();
    }
}
