<?php

namespace App\Modules\BO\AccountsPayable\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TenantAwareModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends TenantAwareModel
{
    use Auditable;

    protected $table = 'bo_suppliers';

    protected $fillable = [
        'tenant_id', 'kode', 'nama', 'npwp', 'telp', 'email', 'alamat',
        'bank_account', 'is_active', 'created_by', 'updated_by', 'deleted_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function invoices(): HasMany
    {
        return $this->hasMany(ApInvoice::class);
    }
}
