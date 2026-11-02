<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Domain\BusinessSupport\Support\BudgetDocNumber;
use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Koreksi Budget (BO: Koreksi Budget Control, dok BC.MA/...).
 *
 * Diajukan dari menu Koreksi Budget (disetujui di layar Approval Koreksi) ATAU dari langkah
 * Struktur Registrasi Invoice Hutang (source_type 'ap_invoice', disetujui lewat Approval Inbox —
 * approval_id terisi). Lihat BudgetCorrectionService.
 */
class BsBudgetCorrection extends Model
{
    use HasTenant;

    protected $table = 'bs_budget_corrections';
    protected $fillable = [
        'tenant_id', 'doc_no', 'year', 'project', 'budget_id', 'cost_center_id', 'account_id',
        'corr_date', 'period_from', 'period_to', 'old_amount', 'new_amount', 'status', 'note',
        'approved_at', 'approved_by', 'created_by', 'approval_id', 'source_type', 'source_id',
    ];
    protected $casts = [
        'year' => 'integer', 'period_from' => 'integer', 'period_to' => 'integer',
        'old_amount' => 'decimal:2', 'new_amount' => 'decimal:2',
        'corr_date' => 'date', 'approved_at' => 'datetime',
    ];

    public const STATUSES = ['baru' => 'Baru', 'approved' => 'Approved', 'rejected' => 'Not Approved'];

    /** document_type approval koreksi budget di Approval Inbox. */
    public const DOCUMENT_TYPE = 'budget_correction';

    /** source_type koreksi yang diajukan dari Registrasi Invoice Hutang. */
    public const SOURCE_INVOICE = 'ap_invoice';

    public function budget(): BelongsTo { return $this->belongsTo(BsBudget::class, 'budget_id'); }
    public function account(): BelongsTo { return $this->belongsTo(BsAccount::class, 'account_id'); }
    public function costCenter(): BelongsTo { return $this->belongsTo(BsCostCenter::class, 'cost_center_id'); }

    public static function nextDocNo(int $year, ?string $project = null, ?string $date = null): string
    {
        return BudgetDocNumber::next('BC.MA', 'bs_budget_corrections', $year, $project, $date);
    }
}
