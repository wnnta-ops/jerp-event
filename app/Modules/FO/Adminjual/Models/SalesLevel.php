<?php

namespace App\Modules\FO\Adminjual\Models;

use App\Models\Concerns\TenantAwareModel;
use App\Models\Project;
use App\Modules\FO\Komisi\Models\Agent;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesLevel extends TenantAwareModel
{
    protected $table = 'fo_sales_levels';

    protected $fillable = [
        'tenant_id', 'project_id', 'sales_type_id', 'code', 'name',
        'parent_id', 'is_active', 'keterangan',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function salesType(): BelongsTo
    {
        return $this->belongsTo(SalesType::class);
    }

    /** Level atasan langsung (mis. SALES NON AREBI → atasan: KOOR NON AREBI). */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(SalesLevel::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(SalesLevel::class, 'parent_id');
    }

    public function agents(): HasMany
    {
        return $this->hasMany(Agent::class);
    }
}
