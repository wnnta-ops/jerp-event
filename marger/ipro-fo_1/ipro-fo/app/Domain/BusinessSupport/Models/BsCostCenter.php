<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Domain\Foundation\Tenant\Models\Project;
use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Cost Center / Divisi — untuk budgeting & alokasi biaya.
 */
class BsCostCenter extends Model
{
    use HasTenant, SoftDeletes;

    protected $table = 'bs_cost_centers';

    protected $fillable = ['tenant_id', 'project_id', 'code', 'name', 'manager', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
