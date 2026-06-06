<?php

namespace App\Modules\BO\Inventory\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TenantAwareModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IvtItem extends TenantAwareModel
{
    use Auditable;

    protected $table = 'bo_ivt_items';

    protected $fillable = [
        'tenant_id', 'kode', 'nama', 'kategori_id', 'satuan', 'stok',
        'min_stok', 'harga_pokok', 'is_active', 'created_by', 'updated_by', 'deleted_by',
    ];

    protected $casts = [
        'stok' => 'decimal:2',
        'min_stok' => 'decimal:2',
        'harga_pokok' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'stok' => 0,
        'min_stok' => 0,
        'harga_pokok' => 0,
        'satuan' => 'PCS',
    ];

    public function kategori(): BelongsTo
    {
        return $this->belongsTo(IvtCategory::class, 'kategori_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(IvtMovement::class, 'item_id');
    }

    public function isBelowMinimum(): bool
    {
        return (float) $this->stok < (float) $this->min_stok;
    }
}
