<?php

declare(strict_types=1);

namespace App\Domain\Foundation\Audit\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'user_id', 'event',
        'auditable_type', 'auditable_id',
        'old_values', 'new_values', 'context',
        'ip_address', 'user_agent', 'created_at',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'context'    => 'array',
        'created_at' => 'datetime',
    ];

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }
}
