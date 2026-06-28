<?php

declare(strict_types=1);

namespace App\Domain\Sales\Booking\Models;

use App\Domain\Foundation\Tenant\Models\Project;
use App\Domain\Master\Customer\Models\Customer;
use App\Domain\Master\Unit\Models\Unit;
use App\Domain\Sales\Booking\Enums\BookingStatus;
use App\Support\Traits\HasAuditTrail;
use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Booking extends Model
{
    use HasAuditTrail, HasFactory, HasTenant, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'project_id', 'booking_number', 'customer_id', 'unit_id',
        'payment_scheme_id', 'sales_person_id', 'agent_id', 'nup_registration_id',
        'booking_date', 'base_price', 'discount_amount', 'discount_pct',
        'gimmick_value', 'ppn_pct', 'ppn_amount', 'total_price',
        'booking_source', 'status', 'notes', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'status'          => BookingStatus::class,
        'booking_date'    => 'date',
        'base_price'      => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'discount_pct'    => 'decimal:2',
        'gimmick_value'   => 'decimal:2',
        'ppn_pct'         => 'decimal:2',
        'ppn_amount'      => 'decimal:2',
        'total_price'     => 'decimal:2',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function paymentScheme(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Sales\Pricing\Models\PaymentScheme::class);
    }

    public function paymentSchedules(): HasMany
    {
        return $this->hasMany(PaymentSchedule::class)->orderBy('installment_no');
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(BookingStatusHistory::class)->latest('changed_at');
    }

    // ── Phase 3 — Computed summary ──────────────────────
    //
    // Ringkasan pembayaran SELALU mengikuti Jadwal Pembayaran: sumber kebenaran
    // "terbayar" adalah kolom paid_amount tiap schedule (yang juga ditampilkan di
    // tabel jadwal). Pada alur normal paid_amount = jumlah alokasi (lihat
    // PaymentSchedule::recomputePaid), sehingga perhitungan ini backward-compatible.

    /**
     * Total amount semua schedule (Nilai Kontrak + Biaya).
     */
    public function getTotalTagihanAttribute(): float
    {
        return (float) $this->paymentSchedules()->sum('amount');
    }

    /**
     * Total terbayar = jumlah paid_amount seluruh jadwal (sinkron dgn tabel jadwal).
     */
    public function getTotalTerbayarAttribute(): float
    {
        return (float) $this->paymentSchedules()->sum('paid_amount');
    }

    /**
     * Total diskon (Free DP / Subsidi DP) = alokasi bermetode 'DN'.
     * Bagian dari "terbayar" yang berasal dari diskon, bukan kas masuk.
     */
    public function getTotalDiskonAttribute(): float
    {
        return (float) \App\Domain\Finance\Payment\Models\PaymentAllocation::query()
            ->whereIn('payment_schedule_id', $this->paymentSchedules()->pluck('id'))
            ->where('payment_method', 'DN')
            ->sum('amount');
    }

    /**
     * Pembayaran kas = total terbayar (per jadwal) dikurangi porsi diskon.
     * Dengan begitu: Pembayaran (kas) + Diskon = Total Terbayar.
     */
    public function getTotalPembayaranAttribute(): float
    {
        return max(0, $this->total_terbayar - $this->total_diskon);
    }

    /**
     * Sisa outstanding = total tagihan - total terbayar (sinkron dgn kolom "sisa" jadwal).
     */
    public function getSisaTagihanAttribute(): float
    {
        return max(0, $this->total_tagihan - $this->total_terbayar);
    }

    /**
     * Persentase pelunasan = total terbayar / total tagihan.
     */
    public function getPersenLunasAttribute(): float
    {
        if ($this->total_tagihan <= 0) return 0.0;
        return round(($this->total_terbayar / $this->total_tagihan) * 100, 2);
    }

    /**
     * Memos terkait booking ini (Free DP / Subsidi / Adjustment).
     */
    public function dpMemos(): HasMany
    {
        return $this->hasMany(\App\Domain\Finance\Memo\Models\Memo::class, 'reference_id')
            ->where('reference_type', 'booking')
            ->whereIn('type', ['free_dp', 'subsidi_dp', 'adjustment']);
    }
}
