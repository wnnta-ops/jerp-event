<?php

declare(strict_types=1);

namespace App\Domain\Finance\Payment\Models;

use App\Domain\Finance\Payment\Enums\AllocationSource;
use App\Domain\Finance\Payment\Enums\PaymentMethod;
use App\Domain\Sales\Booking\Models\PaymentSchedule;
use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Generic polymorphic allocation antara payment source (TTS/Memo/KPR/Manual)
 * dan satu payment schedule.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $payment_schedule_id
 * @property string $source_type
 * @property int|null $source_id
 * @property string $payment_method
 * @property float $amount
 * @property \Carbon\Carbon $allocated_at
 * @property string|null $reference_number
 * @property string|null $notes
 */
class PaymentAllocation extends Model
{
    use HasFactory, HasTenant, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'payment_schedule_id',
        'source_type', 'source_id',
        'payment_method', 'amount',
        'allocated_at', 'reference_number', 'notes',
        'created_by',
    ];

    protected $casts = [
        'amount'         => 'decimal:2',
        'allocated_at'   => 'date',
        'payment_method' => PaymentMethod::class,
    ];

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(PaymentSchedule::class, 'payment_schedule_id');
    }

    /**
     * Polymorphic source — Tts (tts_receipts) | Memo | KprDisbursement.
     */
    public function source(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'source_type', 'source_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    public function getSourceEnumAttribute(): ?AllocationSource
    {
        return AllocationSource::tryFrom($this->source_type);
    }

    public function isCashLike(): bool
    {
        return $this->payment_method?->isCashLike() ?? false;
    }
}
