<?php

declare(strict_types=1);

namespace App\Domain\Master\Unit\Models;

use App\Domain\Foundation\Tenant\Models\Block;
use App\Domain\Foundation\Tenant\Models\Cluster;
use App\Domain\Foundation\Tenant\Models\Project;
use App\Domain\Master\Unit\Enums\UnitStatus;
use App\Support\Traits\HasAuditTrail;
use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Unit extends Model
{
    use HasAuditTrail, HasFactory, HasTenant, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'project_id', 'cluster_id', 'block_id', 'unit_type_id',
        'unit_code', 'address', 'base_price', 'current_price',
        'status', 'attributes',
    ];

    protected $casts = [
        'status'        => UnitStatus::class,
        'attributes'    => 'array',
        'base_price'    => 'decimal:2',
        'current_price' => 'decimal:2',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function cluster(): BelongsTo
    {
        return $this->belongsTo(Cluster::class);
    }

    public function block(): BelongsTo
    {
        return $this->belongsTo(Block::class);
    }

    public function unitType(): BelongsTo
    {
        return $this->belongsTo(UnitType::class);
    }

    public function siteplanPoint(): HasOne
    {
        return $this->hasOne(SiteplanPoint::class);
    }
}
