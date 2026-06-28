<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BsWorkOrderProgressMutation extends Model
{
    use HasTenant;

    protected $table = 'bs_work_order_progress_mutations';
    protected $fillable = [
        'tenant_id', 'work_order_id', 'mutation_date', 'amount',
        'from_account_id', 'from_cost_center_id', 'to_account_id', 'to_cost_center_id',
        'journal_id', 'note', 'created_by', 'status', 'approved_at', 'approved_by',
    ];
    protected $casts = ['mutation_date' => 'date', 'amount' => 'decimal:2', 'approved_at' => 'datetime'];

    public const STATUSES = ['baru' => 'Baru', 'approved' => 'Approved', 'rejected' => 'Not Approved'];

    public function workOrder(): BelongsTo { return $this->belongsTo(BsWorkOrder::class, 'work_order_id'); }
    public function fromAccount(): BelongsTo { return $this->belongsTo(BsAccount::class, 'from_account_id'); }
    public function toAccount(): BelongsTo { return $this->belongsTo(BsAccount::class, 'to_account_id'); }
    public function fromCostCenter(): BelongsTo { return $this->belongsTo(BsCostCenter::class, 'from_cost_center_id'); }
    public function toCostCenter(): BelongsTo { return $this->belongsTo(BsCostCenter::class, 'to_cost_center_id'); }
}
