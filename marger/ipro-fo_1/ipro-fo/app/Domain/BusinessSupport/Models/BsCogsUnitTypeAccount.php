<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** COGS Setup → Set COA Jenis Unit (persediaan unit per jenis). */
class BsCogsUnitTypeAccount extends Model
{
    use HasTenant;

    protected $table = 'bs_cogs_unit_type_accounts';
    protected $fillable = ['tenant_id', 'project_id', 'unit_type', 'account_id'];

    public function account(): BelongsTo { return $this->belongsTo(BsAccount::class, 'account_id'); }
}
