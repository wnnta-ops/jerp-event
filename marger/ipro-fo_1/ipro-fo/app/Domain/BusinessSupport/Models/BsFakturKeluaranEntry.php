<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Domain\Master\Customer\Models\Customer;
use App\Domain\Sales\Booking\Models\Booking;
use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Entri Faktur Pajak Keluaran dari Approval Retensi / Approval UTJ & DP.
 * Muncul di Verifikasi Faktur Pajak (PPN Keluaran) untuk diisi Nomor Faktur Pajak.
 */
class BsFakturKeluaranEntry extends Model
{
    use HasTenant, SoftDeletes;

    protected $table = 'bs_faktur_keluaran_entries';

    protected $fillable = [
        'tenant_id', 'source_type', 'source_id', 'keterangan', 'customer_id', 'booking_id',
        'doc_date', 'dpp', 'ppn', 'total', 'fp_number', 'is_active',
        'converted_at', 'convert_journal_id', 'journal_id', 'created_by',
    ];

    protected $casts = [
        'doc_date'     => 'date',
        'dpp'          => 'decimal:2',
        'ppn'          => 'decimal:2',
        'total'        => 'decimal:2',
        'is_active'    => 'boolean',
        'converted_at' => 'datetime',
    ];

    public function customer(): BelongsTo { return $this->belongsTo(Customer::class, 'customer_id'); }

    public function booking(): BelongsTo { return $this->belongsTo(Booking::class, 'booking_id'); }

    public function journal(): BelongsTo { return $this->belongsTo(BsJournal::class, 'journal_id'); }

    public function convertJournal(): BelongsTo { return $this->belongsTo(BsJournal::class, 'convert_journal_id'); }

    public function isRetensi(): bool { return $this->source_type === 'retensi'; }

    public function isUtjDp(): bool { return $this->source_type === 'utj_dp'; }
}
