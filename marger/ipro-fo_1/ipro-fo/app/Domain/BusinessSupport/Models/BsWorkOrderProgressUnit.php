<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Rincian progress KPL per unit rumah (registrasi massal). */
class BsWorkOrderProgressUnit extends Model
{
    protected $table = 'bs_work_order_progress_units';

    protected $fillable = [
        'tenant_id', 'progress_id', 'unit_id', 'unit_code',
        'line_label', 'satuan', 'volume',
        'progress_pct', 'contract_portion', 'dpp_value',
    ];

    protected $casts = [
        'volume'           => 'decimal:2',
        'progress_pct'     => 'decimal:2',
        'contract_portion' => 'decimal:2',
        'dpp_value'        => 'decimal:2',
    ];

    public function progress(): BelongsTo
    {
        return $this->belongsTo(BsWorkOrderProgress::class, 'progress_id');
    }
}
