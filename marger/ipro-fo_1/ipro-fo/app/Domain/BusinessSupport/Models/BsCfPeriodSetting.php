<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;

/** Angka manual Report Cashflow per bulan: Blokir Angsuran Loan & Buffer. */
class BsCfPeriodSetting extends Model
{
    use HasTenant;

    protected $table = 'bs_cf_period_settings';

    protected $fillable = ['tenant_id', 'period_year', 'period_month', 'blokir_angsuran', 'buffer'];

    protected $casts = ['blokir_angsuran' => 'decimal:2', 'buffer' => 'decimal:2'];
}
