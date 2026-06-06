<?php

namespace App\Models;

use App\Models\Concerns\TenantAwareModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Bank extends TenantAwareModel
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'swift_code',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
