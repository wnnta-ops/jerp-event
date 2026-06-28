<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** COGS Mutasi Budget (+approval). */
class BsCogsBudgetMutation extends Model
{
    use HasTenant;

    protected $table = 'bs_cogs_budget_mutations';
    protected $fillable = [
        'tenant_id', 'doc_no', 'year', 'project_id', 'kind', 'from_budget_id', 'to_budget_id',
        'amount', 'mutation_date', 'status', 'note', 'approved_at', 'approved_by', 'created_by',
    ];
    protected $casts = ['year' => 'integer', 'amount' => 'decimal:2', 'mutation_date' => 'date', 'approved_at' => 'datetime'];

    public const STATUSES = ['baru' => 'Baru', 'approved' => 'Approved', 'rejected' => 'Not Approved'];

    public function fromBudget(): BelongsTo { return $this->belongsTo(BsCogsBudget::class, 'from_budget_id'); }
    public function toBudget(): BelongsTo { return $this->belongsTo(BsCogsBudget::class, 'to_budget_id'); }
}
