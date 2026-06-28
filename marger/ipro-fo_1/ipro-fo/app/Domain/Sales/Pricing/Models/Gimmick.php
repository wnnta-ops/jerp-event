<?php

declare(strict_types=1);

namespace App\Domain\Sales\Pricing\Models;

use App\Domain\Sales\Pricing\Enums\GimmickType;
use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Master gimmick item (semantic: "GimmickItem").
 *
 * @property string $code
 * @property string $name
 * @property GimmickType $gimmick_type
 * @property string $satuan_barang
 * @property float $harga_satuan
 */
class Gimmick extends Model
{
    use HasFactory, HasTenant, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'project_id', 'code', 'name', 'description',
        'gimmick_type', 'satuan_barang', 'harga_satuan', 'value',
        'effective_from', 'effective_to', 'is_active',
    ];

    protected $casts = [
        'gimmick_type'   => GimmickType::class,
        'value'          => 'decimal:2',
        'harga_satuan'   => 'decimal:2',
        'effective_from' => 'date',
        'effective_to'   => 'date',
        'is_active'      => 'boolean',
    ];

    public function packages(): BelongsToMany
    {
        return $this->belongsToMany(GimmickPackage::class, 'gimmick_package_items')
            ->withPivot(['qty_default', 'target_lunas_days'])
            ->withTimestamps();
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
