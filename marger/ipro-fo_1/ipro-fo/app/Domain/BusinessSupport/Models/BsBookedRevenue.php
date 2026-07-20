<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Domain\Master\Customer\Models\Customer;
use App\Domain\Master\Unit\Models\Unit;
use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Booked Revenue — pendapatan ter-booking per customer & unit (deferred).
 */
class BsBookedRevenue extends Model
{
    use HasTenant, SoftDeletes;

    protected $table = 'bs_booked_revenues';

    protected $fillable = [
        'tenant_id', 'br_number', 'booking_date', 'customer_id', 'unit_id', 'project_id',
        'gross_price', 'discount', 'amount', 'receivable_account_id', 'deferred_account_id', 'income_account_id',
        'cost_center_id', 'reference', 'description', 'status',
        'booking_journal_id', 'recognized_at', 'recognition_journal_id',
        'cancelled_at', 'cancel_journal_id', 'created_by',
    ];

    protected $casts = [
        'booking_date'  => 'date',
        'gross_price'   => 'decimal:2',
        'discount'      => 'decimal:2',
        'amount'        => 'decimal:2',
        'recognized_at' => 'datetime',
        'cancelled_at'  => 'datetime',
    ];

    public function customer(): BelongsTo { return $this->belongsTo(Customer::class, 'customer_id'); }

    public function unit(): BelongsTo { return $this->belongsTo(Unit::class, 'unit_id'); }

    public function deferredAccount(): BelongsTo { return $this->belongsTo(BsAccount::class, 'deferred_account_id'); }

    public function bookingJournal(): BelongsTo { return $this->belongsTo(BsJournal::class, 'booking_journal_id'); }

    /** Invoice Piutang yang dibentuk otomatis dari Booked Revenue ini (1:1). */
    public function arInvoice(): HasOne { return $this->hasOne(BsArInvoice::class, 'booked_revenue_id'); }

    public function recognitionJournal(): BelongsTo { return $this->belongsTo(BsJournal::class, 'recognition_journal_id'); }

    public function isBooked(): bool { return $this->status === 'booked'; }

    public function isRecognized(): bool { return $this->status === 'recognized'; }

    public function isCancelled(): bool { return $this->status === 'cancelled'; }
}
