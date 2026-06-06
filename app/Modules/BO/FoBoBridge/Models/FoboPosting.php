<?php

namespace App\Modules\BO\FoBoBridge\Models;

use App\Models\Concerns\TenantAwareModel;
use App\Modules\BO\GeneralLedger\Models\JurnalHeader;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit trail of every FO→BO auto-posting. The unique key
 * (tenant, source_modul, source_no, jenis) guarantees idempotency — it omits
 * deleted_at, so the guard holds even across soft-deletes.
 */
class FoboPosting extends TenantAwareModel
{
    protected $table = 'bo_fobo_postings';

    protected $fillable = [
        'tenant_id', 'source_modul', 'source_no', 'jenis', 'jurnal_id',
        'status', 'amount', 'meta', 'error_message', 'posted_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'meta' => 'array',
        'posted_at' => 'datetime',
    ];

    public function jurnal(): BelongsTo
    {
        return $this->belongsTo(JurnalHeader::class, 'jurnal_id');
    }

    public function isPosted(): bool
    {
        return $this->status === 'POSTED';
    }
}
