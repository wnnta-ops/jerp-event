<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;

/** Rumus laporan arus kas (Set Up Rumus) — definisi yang dipakai laporan. */
class BsCfFormula extends Model
{
    use HasTenant;

    protected $table = 'bs_cf_formulas';
    protected $fillable = ['tenant_id', 'code', 'name', 'expression', 'description', 'sort_order'];
    protected $casts = ['sort_order' => 'integer'];
}
