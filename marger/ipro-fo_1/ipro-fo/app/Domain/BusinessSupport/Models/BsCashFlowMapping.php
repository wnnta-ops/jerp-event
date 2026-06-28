<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Peta akun CoA → kategori arus kas (metode Direct).
 */
class BsCashFlowMapping extends Model
{
    use HasTenant, SoftDeletes;

    protected $table = 'bs_cash_flow_mappings';

    protected $fillable = ['tenant_id', 'account_id', 'category_id'];

    public function account(): BelongsTo { return $this->belongsTo(BsAccount::class, 'account_id'); }

    public function category(): BelongsTo { return $this->belongsTo(BsCashFlowCategory::class, 'category_id'); }
}
