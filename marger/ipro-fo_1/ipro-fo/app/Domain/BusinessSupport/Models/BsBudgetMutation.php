<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BsBudgetMutation extends Model
{
    use HasTenant;

    protected $table = 'bs_budget_mutations';
    protected $fillable = [
        'tenant_id', 'year', 'kind', 'from_budget_id', 'to_budget_id', 'amount', 'mutation_date', 'note', 'created_by',
    ];
    protected $casts = ['year' => 'integer', 'amount' => 'decimal:2', 'mutation_date' => 'date'];

    public function fromBudget(): BelongsTo { return $this->belongsTo(BsBudget::class, 'from_budget_id'); }
    public function toBudget(): BelongsTo { return $this->belongsTo(BsBudget::class, 'to_budget_id'); }
}
