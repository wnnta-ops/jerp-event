<?php

namespace App\Modules\BO\HR\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TenantAwareModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Karyawan extends TenantAwareModel
{
    use Auditable;

    protected $table = 'bo_hr_karyawan';

    protected $fillable = [
        'tenant_id', 'nik', 'nama', 'dept_id', 'jabatan', 'tgl_masuk',
        'status', 'email', 'telp', 'created_by', 'updated_by', 'deleted_by',
    ];

    protected $casts = ['tgl_masuk' => 'date'];

    public function dept(): BelongsTo
    {
        return $this->belongsTo(Departemen::class, 'dept_id');
    }
}
