<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;

/** Data Center → Supplier Center: Kategori Supplier (master terkelola). */
class BsSupplierCategory extends Model
{
    use HasTenant;

    protected $table = 'bs_supplier_categories';
    protected $fillable = ['tenant_id', 'code', 'name', 'is_active', 'notes'];
    protected $casts = ['is_active' => 'boolean'];
}
