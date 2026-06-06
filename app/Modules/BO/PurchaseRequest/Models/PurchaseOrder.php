<?php

namespace App\Modules\BO\PurchaseRequest\Models;

use App\Models\Company;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\TenantAwareModel;
use App\Modules\BO\AccountsPayable\Models\Supplier;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends TenantAwareModel
{
    use Auditable;

    protected $table = 'bo_purchase_orders';

    protected $fillable = [
        'tenant_id', 'no_po', 'tgl', 'company_id', 'pr_id', 'supplier_id',
        'total', 'status', 'keterangan', 'created_by', 'updated_by', 'deleted_by',
    ];

    protected $casts = [
        'tgl' => 'date',
        'total' => 'decimal:2',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(PoLine::class, 'po_id')->orderBy('urutan');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class, 'pr_id');
    }
}
