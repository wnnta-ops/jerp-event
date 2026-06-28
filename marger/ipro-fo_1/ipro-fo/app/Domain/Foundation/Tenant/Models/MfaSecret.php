<?php

declare(strict_types=1);

namespace App\Domain\Foundation\Tenant\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MfaSecret extends Model
{
    protected $fillable = ['user_id', 'secret', 'recovery_codes', 'confirmed_at'];

    protected $casts = [
        'secret'         => 'encrypted',
        'recovery_codes' => 'encrypted:array',
        'confirmed_at'   => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class);
    }
}
