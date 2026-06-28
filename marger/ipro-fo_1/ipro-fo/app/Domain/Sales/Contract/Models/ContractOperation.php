<?php

declare(strict_types=1);

namespace App\Domain\Sales\Contract\Models;

use App\Models\User;
use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Audit seluruh Operasi Kontrak (Adjustment, PPN, Tagihan Custom,
 * Pengalihan Hak, Pindah Unit, Pembatalan).
 */
class ContractOperation extends Model
{
    use HasTenant, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'contract_id', 'booking_id', 'operation_no', 'type',
        'reason', 'old_values', 'new_values', 'amount', 'status', 'processed_by', 'processed_at',
    ];

    protected $casts = [
        'old_values'   => 'array',
        'new_values'   => 'array',
        'amount'       => 'decimal:2',
        'processed_at' => 'datetime',
    ];

    public const TYPES = [
        'adjustment'     => 'Adjustment Kontrak',
        'ppn'            => 'Adjustment PPN',
        'tagihan_custom' => 'Tagihan Custom',
        'ganti_nama'     => 'Pengalihan Hak (Ganti Nama)',
        'pindah_unit'    => 'Pindah Unit',
        'batal'          => 'Pembatalan Kontrak',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }
}
