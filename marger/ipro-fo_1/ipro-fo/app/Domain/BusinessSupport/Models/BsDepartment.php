<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Departemen — dimensi analitik jurnal (eProperty "Departemen", mis. JR).
 */
class BsDepartment extends Model
{
    use HasTenant, SoftDeletes;

    protected $table = 'bs_departments';

    protected $fillable = ['tenant_id', 'code', 'name', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];
}
