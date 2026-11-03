<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BsApKuitansi extends Model
{
    use HasTenant, SoftDeletes;

    protected $table = 'bs_ap_kuitansis';
    protected $fillable = [
        'tenant_id', 'kuitansi_number', 'kuitansi_date', 'supplier_id', 'memo_internal_id', 'cash_account_id', 'advance_account_id',
        'alat_transaksi', 'kasir', 'manual_no', 'bg_number', 'description', 'total', 'status',
        'journal_id', 'void_journal_id', 'voided_at', 'created_by', 'approved_by', 'approved_at',
    ];
    protected $casts = ['kuitansi_date' => 'date', 'voided_at' => 'date', 'approved_at' => 'datetime', 'total' => 'decimal:2'];

    public const ALAT = ['tunai' => 'Tunai', 'transfer' => 'Transfer', 'giro' => 'Cek/Giro'];

    public function supplier(): BelongsTo { return $this->belongsTo(BsSupplier::class, 'supplier_id'); }
    public function cashAccount(): BelongsTo { return $this->belongsTo(BsAccount::class, 'cash_account_id'); }
    public function lines(): HasMany { return $this->hasMany(BsApKuitansiLine::class, 'kuitansi_id'); }
    public function journal(): BelongsTo { return $this->belongsTo(BsJournal::class, 'journal_id'); }
    public function voidJournal(): BelongsTo { return $this->belongsTo(BsJournal::class, 'void_journal_id'); }
    /** Kuitansi pembayaran Memo Urgent (tanpa invoice) — uang keluar didebit ke akun uang muka. */
    public function memo(): BelongsTo { return $this->belongsTo(\App\Domain\ApprovalInbox\Models\MemoInternal::class, 'memo_internal_id'); }
    public function advanceAccount(): BelongsTo { return $this->belongsTo(BsAccount::class, 'advance_account_id'); }
}
