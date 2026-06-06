<?php

namespace App\Modules\FO\Adminjual\Models;

use App\Models\Concerns\TenantAwareModel;
use App\Models\Project;
use App\Modules\FO\Komisi\Models\Agent;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesType extends TenantAwareModel
{
    protected $table = 'fo_sales_types';

    protected $fillable = [
        'tenant_id', 'project_id', 'code', 'name', 'is_active', 'keterangan',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function levels(): HasMany
    {
        return $this->hasMany(SalesLevel::class);
    }

    public function agents(): HasMany
    {
        return $this->hasMany(Agent::class);
    }
}
