<?php

namespace App\Models;

use App\Models\Concerns\TenantAwareModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Numerator extends TenantAwareModel
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'company_id',
        'code',
        'format',
        'reset',
        'last_seq',
        'period',
        'is_active',
    ];

    protected $casts = [
        'last_seq' => 'integer',
        'is_active' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
