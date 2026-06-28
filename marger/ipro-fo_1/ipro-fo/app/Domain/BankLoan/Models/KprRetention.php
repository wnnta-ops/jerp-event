<?php

declare(strict_types=1);

namespace App\Domain\BankLoan\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KprRetention extends Model
{
    protected $table = 'kpr_retentions';

    protected $fillable = ['kpr_realization_id', 'amount', 'released_amount', 'released_at', 'status', 'claim_reason', 'journal_id', 'approval_status', 'reject_reason'];
    protected $casts    = ['released_at' => 'date', 'amount' => 'decimal:2', 'released_amount' => 'decimal:2'];

    public function realization(): BelongsTo
    {
        return $this->belongsTo(KprRealization::class, 'kpr_realization_id');
    }

    /** Riwayat pelepasan retensi (parsial/penuh), urut kronologis. */
    public function releases(): HasMany
    {
        return $this->hasMany(KprRetentionRelease::class, 'kpr_retention_id')->orderBy('id');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\BusinessSupport\Models\BsJournal::class, 'journal_id');
    }
}
