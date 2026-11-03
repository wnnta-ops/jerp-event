<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Domain\BusinessSupport\Support\BudgetDocNumber;
use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BsBudget extends Model
{
    use HasTenant;

    protected $table = 'bs_budgets';
    protected $fillable = [
        'tenant_id', 'doc_no', 'year', 'project', 'cost_center_id', 'account_id', 'amount', 'notes', 'created_by',
        'status', 'submitted_at', 'approved_at', 'approved_by', 'approval_note',
        'm1', 'm2', 'm3', 'm4', 'm5', 'm6', 'm7', 'm8', 'm9', 'm10', 'm11', 'm12',
    ];
    protected $casts = [
        'year' => 'integer', 'amount' => 'decimal:2',
        'submitted_at' => 'datetime', 'approved_at' => 'datetime',
        'm1' => 'decimal:2', 'm2' => 'decimal:2', 'm3' => 'decimal:2', 'm4' => 'decimal:2',
        'm5' => 'decimal:2', 'm6' => 'decimal:2', 'm7' => 'decimal:2', 'm8' => 'decimal:2',
        'm9' => 'decimal:2', 'm10' => 'decimal:2', 'm11' => 'decimal:2', 'm12' => 'decimal:2',
    ];

    public const STATUSES = ['draft' => 'Draft', 'submitted' => 'Diajukan', 'approved' => 'Disetujui', 'rejected' => 'Ditolak'];

    public const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

    public function account(): BelongsTo { return $this->belongsTo(BsAccount::class, 'account_id'); }
    public function costCenter(): BelongsTo { return $this->belongsTo(BsCostCenter::class, 'cost_center_id'); }
    public function allocations(): HasMany { return $this->hasMany(BsBudgetAllocation::class, 'budget_id'); }
    public function corrections(): HasMany { return $this->hasMany(BsBudgetCorrection::class, 'budget_id'); }

    /**
     * Tambah/kurangi anggaran per bulan (1..12 → nilai) dan anggaran tahunannya — dipakai Koreksi
     * Budget & Alokasi. Budget lama yang belum dirinci per bulan dirinci dulu (amount ÷ 12, sisa
     * pembulatan ke Desember) supaya bulan lain tidak ikut hilang. Belum disimpan.
     *
     * @param  array<int,float>  $delta
     */
    public function tambahBulan(array $delta): void
    {
        $rinci = 0.0;
        for ($m = 1; $m <= 12; $m++) {
            $rinci += abs((float) ($this->{'m'.$m} ?? 0));
        }
        if ($rinci <= 0 && (float) $this->amount != 0.0) {
            $per = round((float) $this->amount / 12, 2);
            for ($m = 1; $m <= 11; $m++) {
                $this->{'m'.$m} = $per;
            }
            $this->m12 = round((float) $this->amount - $per * 11, 2);
        }
        $total = 0.0;
        foreach ($delta as $m => $v) {
            $this->{'m'.(int) $m} = round((float) ($this->{'m'.(int) $m} ?? 0) + (float) $v, 2);
            $total += (float) $v;
        }
        $this->amount = round((float) $this->amount + $total, 2);
    }

    /** @return array<int,float> nilai anggaran bulan 1..12 (fallback rata bila belum diisi bulanan). */
    public function monthsArray(): array
    {
        $out = [];
        $sum = 0.0;
        for ($m = 1; $m <= 12; $m++) {
            $v = (float) ($this->{'m'.$m} ?? 0);
            $out[$m] = $v;
            $sum += $v;
        }
        // Kompat: baris lama hanya punya 'amount' tahunan → sebar rata 1/12 untuk tampilan bulanan.
        if ($sum <= 0 && (float) $this->amount > 0) {
            $per = round((float) $this->amount / 12, 2);
            for ($m = 1; $m <= 12; $m++) {
                $out[$m] = $per;
            }
        }

        return $out;
    }

    /** Nomor dokumen master budget: BC/MUP/{proj}/MM/YY/#####. */
    public static function nextDocNo(int $year, ?string $project = null, ?string $date = null): string
    {
        return BudgetDocNumber::next('BC', 'bs_budgets', $year, $project, $date);
    }
}
