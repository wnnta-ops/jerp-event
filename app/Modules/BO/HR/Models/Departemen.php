<?php

namespace App\Modules\BO\HR\Models;

use App\Models\Concerns\TenantAwareModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Departemen extends TenantAwareModel
{
    protected $table = 'bo_hr_dept';

    protected $fillable = ['tenant_id', 'kode', 'nama', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function karyawan(): HasMany
    {
        return $this->hasMany(Karyawan::class, 'dept_id');
    }
}
