<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Domain\BusinessSupport\Support\BudgetDocNumber;
use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Alokasi Budget (BO: Alokasi Budget Control, dok BC.AL/...).
 */
class BsBudgetAllocation extends Model
{
    use HasTenant;

    protected $table = 'bs_budget_allocations';
    protected $fillable = [
        'tenant_id', 'doc_no', 'year', 'project', 'budget_id', 'cost_center_id', 'account_id',
        'alloc_date', 'period_from', 'period_to', 'amount', 'total_budget', 'status', 'notes',
        'approved_at', 'approved_by', 'created_by',
    ];
    protected $casts = [
        'year' => 'integer', 'period_from' => 'integer', 'period_to' => 'integer',
        'amount' => 'decimal:2', 'total_budget' => 'decimal:2',
        'alloc_date' => 'date', 'approved_at' => 'datetime',
    ];

    public const STATUSES = ['baru' => 'Baru', 'approved' => 'Approved', 'rejected' => 'Not Approved'];

    public function budget(): BelongsTo { return $this->belongsTo(BsBudget::class, 'budget_id'); }
    public function account(): BelongsTo { return $this->belongsTo(BsAccount::class, 'account_id'); }
    public function costCenter(): BelongsTo { return $this->belongsTo(BsCostCenter::class, 'cost_center_id'); }

    public static function nextDocNo(int $year, ?string $project = null, ?string $date = null): string
    {
        return BudgetDocNumber::next('BC.AL', 'bs_budget_allocations', $year, $project, $date);
    }
}
