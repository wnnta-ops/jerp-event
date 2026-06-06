<?php

namespace App\Modules\BO\PurchaseRequest\Models;

use App\Models\Company;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\TenantAwareModel;
use App\Modules\BO\HR\Models\Departemen;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseRequest extends TenantAwareModel
{
    use Auditable;

    protected $table = 'bo_purchase_requests';

    protected $fillable = [
        'tenant_id', 'no_pr', 'tgl', 'company_id', 'dept_id', 'keperluan',
        'estimasi_total', 'status', 'approval_level', 'po_id', 'keterangan',
        'created_by', 'updated_by', 'deleted_by',
    ];

    protected $casts = [
        'tgl' => 'date',
        'estimasi_total' => 'decimal:2',
        'approval_level' => 'integer',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(PrLine::class, 'pr_id')->orderBy('urutan');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function dept(): BelongsTo
    {
        return $this->belongsTo(Departemen::class, 'dept_id');
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'po_id');
    }
}
