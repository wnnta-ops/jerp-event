<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BsWorkOrderAddendum extends Model
{
    use HasTenant;

    protected $table = 'bs_work_order_addendums';
    protected $fillable = [
        'tenant_id', 'work_order_id', 'addendum_date', 'kind', 'value_delta',
        'old_contract_value', 'new_contract_value', 'old_ppn_pct', 'new_ppn_pct', 'note', 'created_by',
    ];
    protected $casts = [
        'addendum_date' => 'date',
        'value_delta' => 'decimal:2', 'old_contract_value' => 'decimal:2', 'new_contract_value' => 'decimal:2',
        'old_ppn_pct' => 'decimal:2', 'new_ppn_pct' => 'decimal:2',
    ];

    public function workOrder(): BelongsTo { return $this->belongsTo(BsWorkOrder::class, 'work_order_id'); }
}
