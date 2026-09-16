<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rincian akun lawan satu transaksi kas (#15 — split transaction).
 */
class BsCashTransactionLine extends Model
{
    use HasTenant;

    protected $table = 'bs_cash_transaction_lines';
    protected $fillable = ['tenant_id', 'cash_transaction_id', 'account_id', 'cost_center_id', 'amount', 'description'];
    protected $casts = ['amount' => 'decimal:2'];

    public function transaction(): BelongsTo { return $this->belongsTo(BsCashTransaction::class, 'cash_transaction_id'); }

    public function account(): BelongsTo { return $this->belongsTo(BsAccount::class, 'account_id'); }
}
