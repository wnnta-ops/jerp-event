<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class BsStockCategory extends Model
{
    use HasTenant, SoftDeletes;

    protected $table = 'bs_stock_categories';
    protected $fillable = ['tenant_id', 'code', 'name', 'is_active'];
    protected $casts = ['is_active' => 'boolean'];
}
