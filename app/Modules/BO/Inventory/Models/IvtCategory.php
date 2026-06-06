<?php

namespace App\Modules\BO\Inventory\Models;

use App\Models\Concerns\TenantAwareModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IvtCategory extends TenantAwareModel
{
    protected $table = 'bo_ivt_categories';

    protected $fillable = ['tenant_id', 'kode', 'nama', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function items(): HasMany
    {
        return $this->hasMany(IvtItem::class, 'kategori_id');
    }
}
