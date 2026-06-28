<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BsCashTransaction extends Model
{
    use HasTenant, SoftDeletes;

    protected $table = 'bs_cash_transactions';
    protected $fillable = [
        'tenant_id', 'trx_number', 'trx_type', 'trx_date', 'cash_account_id', 'counter_account_id',
        'amount', 'payee', 'description', 'reference', 'journal_id', 'created_by',
        'approval_status', 'approved_by', 'approved_at',
    ];
    protected $casts = ['trx_date' => 'date', 'amount' => 'decimal:2', 'approved_at' => 'datetime'];

    public const TYPES = [
        'in'       => 'Kas Masuk (BKM)',
        'out'      => 'Kas Keluar (BKK)',
        'transfer' => 'Transfer Antar Rekening',
    ];

    public function cashAccount(): BelongsTo { return $this->belongsTo(BsAccount::class, 'cash_account_id'); }
    public function counterAccount(): BelongsTo { return $this->belongsTo(BsAccount::class, 'counter_account_id'); }

    /** Rincian akun lawan (#15 — split transaction; bisa >1 akun). */
    public function lines(): HasMany { return $this->hasMany(BsCashTransactionLine::class, 'cash_transaction_id'); }
}
