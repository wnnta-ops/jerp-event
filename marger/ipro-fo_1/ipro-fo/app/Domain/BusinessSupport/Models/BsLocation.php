<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Lokasi — dimensi analitik jurnal (eProperty "Lokasi").
 */
class BsLocation extends Model
{
    use HasTenant, SoftDeletes;

    protected $table = 'bs_locations';

    protected $fillable = ['tenant_id', 'code', 'name', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];
}
