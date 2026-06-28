<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;

class BsFiscalPeriod extends Model
{
    use HasTenant;

    protected $table = 'bs_fiscal_periods';
    protected $fillable = ['tenant_id', 'year', 'month', 'status', 'closed_at', 'late_closed', 'late_note'];
    protected $casts = ['year' => 'integer', 'month' => 'integer', 'closed_at' => 'datetime', 'late_closed' => 'boolean'];
}
