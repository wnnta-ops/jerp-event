<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;

/** COGS Setup → Lokasi (cluster/tower). */
class BsCogsLocation extends Model
{
    use HasTenant;

    protected $table = 'bs_cogs_locations';
    protected $fillable = ['tenant_id', 'project_id', 'code', 'name', 'is_active', 'notes', 'created_by'];
    protected $casts = ['is_active' => 'boolean'];
}
