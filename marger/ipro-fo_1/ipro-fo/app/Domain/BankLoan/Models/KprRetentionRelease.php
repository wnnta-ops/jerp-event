<?php

declare(strict_types=1);

namespace App\Domain\BankLoan\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu event pelepasan retensi (parsial atau penuh) — komponen riwayat di detail KPR.
 */
class KprRetentionRelease extends Model
{
    protected $table = 'kpr_retention_releases';

    protected $fillable = ['kpr_retention_id', 'amount', 'released_at', 'reason', 'tts_id',
        'journal_id', 'approval_status', 'reject_reason', 'rejected_at'];
    protected $casts    = ['released_at' => 'date', 'amount' => 'decimal:2', 'rejected_at' => 'datetime'];

    public function retention(): BelongsTo
    {
        return $this->belongsTo(KprRetention::class, 'kpr_retention_id');
    }

    /** Jurnal MILIK TRANCHE INI — tiap pelepasan dijurnal & di-approve sendiri-sendiri. */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\BusinessSupport\Models\BsJournal::class, 'journal_id');
    }
}
