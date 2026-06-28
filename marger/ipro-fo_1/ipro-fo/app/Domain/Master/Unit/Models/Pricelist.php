<?php

declare(strict_types=1);

namespace App\Domain\Master\Unit\Models;

use App\Domain\Foundation\Tenant\Models\Project;
use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Price List (eProperty: Setup Sales → Price List). Daftar harga unit per periode.
 */
class Pricelist extends Model
{
    use HasFactory, HasTenant, SoftDeletes;

    protected $table = 'pricelists';

    protected $fillable = [
        'tenant_id', 'project_id', 'name', 'effective_from', 'effective_to', 'is_active',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'effective_to'   => 'date',
        'is_active'      => 'boolean',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(PricelistItem::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
