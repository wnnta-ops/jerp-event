<?php

namespace App\Models;

use App\Models\Concerns\TenantAwareModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends TenantAwareModel
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'npwp',
        'address',
        'phone',
        'email',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }
}
