<?php

namespace App\Models;

use App\Models\Concerns\TenantAwareModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Project extends TenantAwareModel
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'company_id',
        'code',
        'name',
        'address',
        'is_active',
        'siteplan_image_path',
        'siteplan_width',
        'siteplan_height',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'siteplan_width' => 'integer',
        'siteplan_height' => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
