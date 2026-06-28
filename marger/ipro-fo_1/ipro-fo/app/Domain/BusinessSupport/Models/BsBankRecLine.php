<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BsBankRecLine extends Model
{
    public $timestamps = false;
    protected $table = 'bs_bank_rec_lines';
    protected $fillable = ['reconciliation_id', 'journal_line_id', 'journal_id', 'tx_date', 'description', 'debit', 'credit', 'is_cleared'];
    protected $casts = ['tx_date' => 'date', 'debit' => 'decimal:2', 'credit' => 'decimal:2', 'is_cleared' => 'boolean'];

    public function reconciliation(): BelongsTo { return $this->belongsTo(BsBankReconciliation::class, 'reconciliation_id'); }
}
