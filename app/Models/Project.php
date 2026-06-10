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
        'siteplan_show_info',
        'siteplan_show_status',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'siteplan_width' => 'integer',
        'siteplan_height' => 'integer',
        'siteplan_show_info' => 'boolean',
        'siteplan_show_status' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
