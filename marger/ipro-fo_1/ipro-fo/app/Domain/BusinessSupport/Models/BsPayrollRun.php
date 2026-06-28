<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BsPayrollRun extends Model
{
    use HasTenant, SoftDeletes;

    protected $table = 'bs_payroll_runs';
    protected $fillable = [
        'tenant_id', 'run_number', 'period_year', 'period_month', 'run_date', 'description',
        'total_bruto', 'total_deduction', 'total_net', 'status', 'journal_id', 'created_by',
    ];
    protected $casts = [
        'run_date' => 'date', 'period_year' => 'integer', 'period_month' => 'integer',
        'total_bruto' => 'decimal:2', 'total_deduction' => 'decimal:2', 'total_net' => 'decimal:2',
    ];

    public function lines(): HasMany { return $this->hasMany(BsPayrollLine::class, 'payroll_run_id'); }
}
