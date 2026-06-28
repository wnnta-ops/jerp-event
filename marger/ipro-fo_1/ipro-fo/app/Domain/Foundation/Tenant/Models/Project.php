<?php

declare(strict_types=1);

namespace App\Domain\Foundation\Tenant\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Project extends Model
{
    use HasFactory, HasTenant, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'company_id', 'code', 'name', 'location',
        'launched_at', 'siteplan_meta', 'is_active',
    ];

    protected $casts = [
        'launched_at'   => 'date',
        'siteplan_meta' => 'array',
        'is_active'     => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function clusters(): HasMany
    {
        return $this->hasMany(Cluster::class);
    }
}
