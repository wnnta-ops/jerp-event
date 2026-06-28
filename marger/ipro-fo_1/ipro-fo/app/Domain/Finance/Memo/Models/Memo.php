<?php

declare(strict_types=1);

namespace App\Domain\Finance\Memo\Models;

use App\Domain\Finance\Memo\Enums\MemoStatus;
use App\Domain\Finance\Memo\Enums\MemoType;
use App\Domain\Finance\Payment\Models\PaymentAllocation;
use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $memo_number
 * @property MemoType $type
 * @property MemoStatus $status
 * @property string $reference_type
 * @property int $reference_id
 * @property float $amount
 */
class Memo extends Model
{
    use HasFactory, HasTenant, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'memo_number', 'type', 'memo_date', 'amount',
        'reference_type', 'reference_id',
        'description', 'reason',
        'bank_account_id', 'status',
        'approval_id',
        'created_by',
        'approved_by', 'approved_at',
        'rejected_by', 'rejected_at',
        'cancelled_by', 'cancelled_at',
    ];

    protected $casts = [
        'type'         => MemoType::class,
        'status'       => MemoStatus::class,
        'memo_date'    => 'date',
        'approved_at'  => 'datetime',
        'rejected_at'  => 'datetime',
        'cancelled_at' => 'datetime',
        'amount'       => 'decimal:2',
    ];

    // ── Relationships ─────────────────────────────────────

    public function reference()
    {
        // Polymorphic via reference_type/reference_id (morph map).
        return $this->morphTo(__FUNCTION__, 'reference_type', 'reference_id');
    }

    /**
     * Allocations yang dibuat dari memo ini.
     */
    public function allocations(): MorphMany
    {
        return $this->morphMany(PaymentAllocation::class, 'source', 'source_type', 'source_id');
    }

    public function approval(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Approval\Models\Approval::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'approved_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'cancelled_by');
    }

    // ── Helpers ───────────────────────────────────────────

    public function isActive(): bool
    {
        return $this->status === MemoStatus::Approved;
    }

    public function canBeCancelled(): bool
    {
        return in_array($this->status, [MemoStatus::PendingApproval, MemoStatus::Approved], true);
    }
}
