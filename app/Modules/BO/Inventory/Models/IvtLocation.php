<?php

namespace App\Modules\BO\Inventory\Models;

use App\Models\Concerns\TenantAwareModel;

class IvtLocation extends TenantAwareModel
{
    protected $table = 'bo_ivt_locations';

    protected $fillable = ['tenant_id', 'kode', 'nama', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];
}
