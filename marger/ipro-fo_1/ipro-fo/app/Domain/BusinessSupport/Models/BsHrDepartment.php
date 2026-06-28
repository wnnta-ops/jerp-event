<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;

/** Data Center → Employee Center: HR Department (master). */
class BsHrDepartment extends Model
{
    use HasTenant;

    protected $table = 'bs_hr_departments';
    protected $fillable = ['tenant_id', 'code', 'name', 'head', 'is_active', 'notes'];
    protected $casts = ['is_active' => 'boolean'];
}
