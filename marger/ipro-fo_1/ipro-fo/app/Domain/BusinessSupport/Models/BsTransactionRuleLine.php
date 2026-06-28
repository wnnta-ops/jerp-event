<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Baris template Transaction Rule (debit/kredit + peran akun + basis nilai).
 */
class BsTransactionRuleLine extends Model
{
    protected $table = 'bs_transaction_rule_lines';

    protected $fillable = ['rule_id', 'sort_order', 'side', 'account_role', 'account_id', 'amount_basis', 'description'];

    protected $casts = ['sort_order' => 'integer'];

    public function rule(): BelongsTo { return $this->belongsTo(BsTransactionRule::class, 'rule_id'); }

    public function account(): BelongsTo { return $this->belongsTo(BsAccount::class, 'account_id'); }
}
