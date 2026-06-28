<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BsBankReconciliation extends Model
{
    use HasTenant, SoftDeletes;

    protected $table = 'bs_bank_reconciliations';
    protected $fillable = [
        'tenant_id', 'account_id', 'statement_date', 'statement_balance', 'book_balance',
        'status', 'notes', 'reconciled_at', 'created_by',
    ];
    protected $casts = [
        'statement_date' => 'date', 'reconciled_at' => 'date',
        'statement_balance' => 'decimal:2', 'book_balance' => 'decimal:2',
    ];

    public function account(): BelongsTo { return $this->belongsTo(BsAccount::class, 'account_id'); }
    public function lines(): HasMany { return $this->hasMany(BsBankRecLine::class, 'reconciliation_id'); }
}
