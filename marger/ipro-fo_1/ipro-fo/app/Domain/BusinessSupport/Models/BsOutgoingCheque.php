<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * BG/Cek keluar perusahaan (#55) — diterbitkan ke supplier/vendor.
 * Siklus: available (dimiliki) → issued (beredar) → cleared (dicairkan).
 * #74: status void (dibatalkan). Stock kosong hasil import = available (amount/cheque_date null).
 */
class BsOutgoingCheque extends Model
{
    use HasTenant, SoftDeletes;

    protected $table = 'bs_outgoing_cheques';
    protected $fillable = [
        'tenant_id', 'cheque_number', 'cheque_type', 'bank_account_id', 'supplier_id', 'payee_type', 'payee', 'amount',
        'cheque_date', 'handover_date', 'cleared_date', 'signer_name', 'signer_name_2',
        'description', 'recipient_bank', 'recipient_account_no', 'void_date', 'void_reason',
        'status', 'handover_journal_id', 'clear_journal_id', 'void_journal_id', 'created_by',
    ];
    protected $casts = [
        'amount' => 'decimal:2', 'cheque_date' => 'date', 'handover_date' => 'date',
        'cleared_date' => 'date', 'void_date' => 'date',
    ];

    public const TYPES = ['cek' => 'Cek', 'bg' => 'Bilyet Giro'];

    public const STATUSES = [
        'available' => 'Dimiliki',
        'issued'    => 'Beredar',
        'cleared'   => 'Dicairkan',
        'void'      => 'Void',
    ];

    public function bankAccount(): BelongsTo { return $this->belongsTo(BsAccount::class, 'bank_account_id'); }

    public function supplier(): BelongsTo { return $this->belongsTo(BsSupplier::class, 'supplier_id'); }

    public function lines(): HasMany { return $this->hasMany(BsOutgoingChequeLine::class, 'outgoing_cheque_id'); }

    public function handoverJournal(): BelongsTo { return $this->belongsTo(BsJournal::class, 'handover_journal_id'); }

    public function clearJournal(): BelongsTo { return $this->belongsTo(BsJournal::class, 'clear_journal_id'); }

    public function voidJournal(): BelongsTo { return $this->belongsTo(BsJournal::class, 'void_journal_id'); }
}
