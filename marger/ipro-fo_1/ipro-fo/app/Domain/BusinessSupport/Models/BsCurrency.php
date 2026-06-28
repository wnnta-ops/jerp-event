<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Master mata uang (Multi Currency).
 */
class BsCurrency extends Model
{
    use HasTenant, SoftDeletes;

    protected $table = 'bs_currencies';

    protected $fillable = ['tenant_id', 'code', 'name', 'symbol', 'is_base', 'is_active'];

    protected $casts = ['is_base' => 'boolean', 'is_active' => 'boolean'];
}
