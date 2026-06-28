<?php

declare(strict_types=1);

namespace App\Domain\Sales\Pricing\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Master paket gimmick per cluster (mis. "CLUSTER 3.4" bundle).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $project_id
 * @property string|null $cluster_code
 * @property string $code
 * @property string $name
 * @property bool $is_active
 */
class GimmickPackage extends Model
{
    use HasFactory, HasTenant, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'project_id', 'cluster_code', 'code', 'name',
        'description', 'valid_from', 'valid_until', 'is_active',
    ];

    protected $casts = [
        'valid_from'  => 'date',
        'valid_until' => 'date',
        'is_active'   => 'boolean',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Foundation\Tenant\Models\Project::class);
    }

    public function items(): BelongsToMany
    {
        return $this->belongsToMany(Gimmick::class, 'gimmick_package_items')
            ->withPivot(['qty_default', 'target_lunas_days'])
            ->withTimestamps();
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Total nilai semua items dalam package (preview marketing cost).
     */
    public function getTotalValueAttribute(): float
    {
        return $this->items->sum(fn ($item) => $item->harga_satuan * $item->pivot->qty_default);
    }
}
