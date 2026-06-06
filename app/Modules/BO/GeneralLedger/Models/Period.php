<?php

namespace App\Modules\BO\GeneralLedger\Models;

use App\Models\Company;
use App\Models\Concerns\TenantAwareModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Accounting period lock. A CLOSED period rejects further postings/edits.
 */
class Period extends TenantAwareModel
{
    protected $table = 'bo_periods';

    protected $fillable = [
        'tenant_id',
        'company_id',
        'tahun',
        'bulan',
        'periode',
        'status',
        'closed_by',
        'closed_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'tahun' => 'integer',
        'bulan' => 'integer',
        'closed_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function isClosed(): bool
    {
        return $this->status === 'CLOSED';
    }
}
