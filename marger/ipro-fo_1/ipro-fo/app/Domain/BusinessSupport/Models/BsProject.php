<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Master Project (Contractor Management → Setup → Project).
 */
class BsProject extends Model
{
    use HasTenant;

    protected $table = 'bs_projects';
    protected $fillable = ['tenant_id', 'code', 'name', 'dept', 'is_active', 'notes', 'created_by'];
    protected $casts = ['is_active' => 'boolean'];
}
