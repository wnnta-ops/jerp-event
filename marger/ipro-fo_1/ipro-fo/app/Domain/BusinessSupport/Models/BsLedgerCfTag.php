<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tag kategori arus kas (Direct & Indirect) per baris jurnal (mutasi Rekening Ledger).
 */
class BsLedgerCfTag extends Model
{
    use HasTenant, SoftDeletes;

    protected $table = 'bs_ledger_cf_tags';

    protected $fillable = ['tenant_id', 'journal_line_id', 'cf_direct_category_id', 'cf_indirect_category_id'];

    public function directCategory(): BelongsTo { return $this->belongsTo(BsCashFlowCategory::class, 'cf_direct_category_id'); }

    public function indirectCategory(): BelongsTo { return $this->belongsTo(BsCashFlowCategory::class, 'cf_indirect_category_id'); }
}
