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
        'corr_date', 'period_from', 'period_to', 'old_amount', 'new_amount', 'months', 'status', 'note',
        'approved_at', 'approved_by', 'created_by', 'approval_id', 'source_type', 'source_id',
    ];
    protected $casts = [
        'year' => 'integer', 'period_from' => 'integer', 'period_to' => 'integer',
        'old_amount' => 'decimal:2', 'new_amount' => 'decimal:2', 'months' => 'array',
        'corr_date' => 'date', 'approved_at' => 'datetime',
    ];

    /**
     * Tambahan per bulan (1..12 → nilai, boleh minus). Koreksi lama tanpa rincian bulanan: selisih
     * nilai baru − lama dibagi rata ke bulan period_from..period_to.
     *
     * @return array<int,float>
     */
    public function tambahanBulan(): array
    {
        $out = [];
        if (is_array($this->months) && $this->months !== []) {
            foreach ($this->months as $m => $v) {
                if ((int) $m >= 1 && (int) $m <= 12 && round((float) $v, 2) != 0.0) {
                    $out[(int) $m] = round((float) $v, 2);
                }
            }

            return $out;
        }
        $delta = round((float) $this->new_amount - (float) $this->old_amount, 2);
        $from = max(1, (int) $this->period_from);
        $to = min(12, (int) $this->period_to);
        $per = round($delta / max(1, $to - $from + 1), 2);
        for ($m = $from; $m <= $to; $m++) {
            $out[$m] = $per;
        }

        return $out;
    }

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
