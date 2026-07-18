<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Domain\Master\Customer\Models\Customer;
use App\Domain\Sales\Booking\Models\Booking;
use App\Models\User;
use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Penanda satu baris UM Penjualan sudah dikompensasi dengan Piutang (AR).
 *
 * Bukan transaksi akuntansi: tidak memposting jurnal apa pun. Perannya memindahkan baris dari
 * antrean "UM Penjualan" ke layar "Rekonsiliasi Piutang" dan menyimpan nilainya sebagai snapshot.
 *
 * @see database/migrations/2026_07_18_110000_create_ar_um_compensations.php
 */
class BsArUmCompensation extends Model
{
    use HasTenant, SoftDeletes;

    /** Sumber baris UM — harus sama dengan yang dipakai UmPenjualanController::sourceRows(). */
    public const SOURCES = [
        'retensi'   => 'Approval Retensi — DAJAM',
        'pencairan' => 'Approval Retensi — Pencairan KPR',
        'utj_dp'    => 'Approval UM Penjualan (UTJ/DP)',
    ];

    protected $table = 'bs_ar_um_compensations';
    protected $fillable = [
        'tenant_id', 'source_type', 'source_id', 'booking_id', 'customer_id',
        'amount', 'note', 'compensated_at', 'compensated_by',
    ];
    protected $casts = [
        'amount'         => 'decimal:2',
        'compensated_at' => 'datetime',
    ];

    public function booking(): BelongsTo { return $this->belongsTo(Booking::class, 'booking_id'); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class, 'customer_id'); }
    public function compensatedBy(): BelongsTo { return $this->belongsTo(User::class, 'compensated_by'); }

    public function getSourceLabelAttribute(): string
    {
        return self::SOURCES[$this->source_type] ?? $this->source_type;
    }
}
