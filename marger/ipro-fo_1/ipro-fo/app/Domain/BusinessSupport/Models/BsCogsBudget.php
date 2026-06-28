<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** COGS Budget proyek (per cluster/kategori/COA). */
class BsCogsBudget extends Model
{
    use HasTenant;

    protected $table = 'bs_cogs_budgets';
    protected $fillable = ['tenant_id', 'year', 'project_id', 'location_id', 'category_id', 'account_id', 'amount', 'notes', 'created_by'];
    protected $casts = ['year' => 'integer', 'amount' => 'decimal:2'];

    public function category(): BelongsTo { return $this->belongsTo(BsCogsCategory::class, 'category_id'); }
    public function location(): BelongsTo { return $this->belongsTo(BsCogsLocation::class, 'location_id'); }
    public function account(): BelongsTo { return $this->belongsTo(BsAccount::class, 'account_id'); }
}
