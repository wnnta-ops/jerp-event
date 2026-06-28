<?php

declare(strict_types=1);

namespace App\Domain\Collection\Pjt\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PjtHeader extends Model
{
    use HasTenant;

    protected $fillable = ['tenant_id', 'pjt_number', 'customer_id', 'period_at', 'transaction_type', 'total_overdue', 'status'];
    protected $casts    = ['period_at' => 'date', 'total_overdue' => 'decimal:2'];

    public function customer(): BelongsTo { return $this->belongsTo(\App\Domain\Master\Customer\Models\Customer::class); }
    public function details(): HasMany    { return $this->hasMany(PjtDetail::class); }
}
