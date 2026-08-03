<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Angka rencana arus kas per kategori per periode: proyeksi (cashplan) & WNPB. */
class BsCfFigure extends Model
{
    use HasTenant;

    protected $table = 'bs_cf_figures';
    protected $fillable = ['tenant_id', 'cf_category_id', 'period_year', 'period_month', 'projection', 'wnpb'];
    protected $casts = [
        'period_year' => 'integer', 'period_month' => 'integer',
        'projection' => 'decimal:2', 'wnpb' => 'decimal:2',
    ];

    public function category(): BelongsTo { return $this->belongsTo(BsCfCategory::class, 'cf_category_id'); }
}
