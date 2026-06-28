<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Master Supplier / Vendor (Purchasing & Account Payable).
 */
class BsSupplier extends Model
{
    use HasTenant, SoftDeletes;

    protected $table = 'bs_suppliers';

    protected $fillable = [
        'tenant_id', 'code', 'name', 'category', 'category_id', 'contact_person', 'phone', 'email',
        'address', 'city', 'npwp', 'bank_name', 'bank_account_no', 'bank_account_name', 'is_active',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public function categoryRef(): BelongsTo { return $this->belongsTo(BsSupplierCategory::class, 'category_id'); }
}
