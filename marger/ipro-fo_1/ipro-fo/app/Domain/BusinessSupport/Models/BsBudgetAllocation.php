<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Domain\BusinessSupport\Support\BudgetDocNumber;
use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Alokasi Budget (dok BC.AL/...) — memindah anggaran dari budget asal (budget_id, COA lama) ke
 * budget tujuan (to_budget_id, COA baru) per bulan (`months`), langsung berlaku tanpa approval.
 * Baris lama tanpa to_budget_id = alokasi model lama (penyisihan dalam satu budget).
 */
class BsBudgetAllocation extends Model
{
    use HasTenant;

    protected $table = 'bs_budget_allocations';
    protected $fillable = [
        'tenant_id', 'doc_no', 'year', 'project', 'budget_id', 'to_budget_id', 'cost_center_id', 'account_id',
        'alloc_date', 'period_from', 'period_to', 'amount', 'months', 'total_budget', 'status', 'notes', 'source_type', 'source_id',
        'approved_at', 'approved_by', 'created_by',
    ];
    protected $casts = [
        'year' => 'integer', 'period_from' => 'integer', 'period_to' => 'integer',
        'amount' => 'decimal:2', 'total_budget' => 'decimal:2', 'months' => 'array',
        'alloc_date' => 'date', 'approved_at' => 'datetime',
    ];

    public const STATUSES = ['baru' => 'Baru', 'approved' => 'Approved', 'rejected' => 'Not Approved'];

    public const SOURCE_INVOICE = 'ap_invoice';

    public function budget(): BelongsTo { return $this->belongsTo(BsBudget::class, 'budget_id'); }
    public function toBudget(): BelongsTo { return $this->belongsTo(BsBudget::class, 'to_budget_id'); }
    public function account(): BelongsTo { return $this->belongsTo(BsAccount::class, 'account_id'); }
    public function costCenter(): BelongsTo { return $this->belongsTo(BsCostCenter::class, 'cost_center_id'); }

    public static function nextDocNo(int $year, ?string $project = null, ?string $date = null): string
    {
        return BudgetDocNumber::next('BC.AL', 'bs_budget_allocations', $year, $project, $date);
    }
}
