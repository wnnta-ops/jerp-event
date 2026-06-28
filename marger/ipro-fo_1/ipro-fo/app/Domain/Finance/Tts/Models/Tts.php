<?php

declare(strict_types=1);

namespace App\Domain\Finance\Tts\Models;

use App\Support\Traits\HasAuditTrail;
use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tts extends Model
{
    use HasAuditTrail, HasFactory, HasTenant, SoftDeletes;

    protected $table = 'tts_receipts';

    protected $fillable = [
        'tenant_id', 'project_id', 'tts_number', 'customer_id', 'booking_id',
        'amount', 'payment_method', 'received_date', 'bank_account_id',
        'reference_no', 'status', 'notes', 'received_by', 'posted_by', 'posted_at', 'journal_id',
    ];

    protected $casts = [
        'received_date' => 'date',
        'posted_at'     => 'datetime',
        'amount'        => 'decimal:2',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Master\Customer\Models\Customer::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Sales\Booking\Models\Booking::class);
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\BusinessSupport\Models\BsJournal::class, 'journal_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(TtsAllocation::class, 'tts_receipt_id');
    }

    public function giro(): HasOne
    {
        return $this->hasOne(TtsGiro::class, 'tts_receipt_id');
    }
}
