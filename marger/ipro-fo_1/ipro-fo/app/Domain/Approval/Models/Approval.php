<?php

declare(strict_types=1);

namespace App\Domain\Approval\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Approval extends Model
{
    use HasFactory, HasTenant;

    protected $fillable = [
        'tenant_id', 'approval_chain_id', 'document_type',
        'approvable_type', 'approvable_id', 'payload',
        'status', 'submitted_by', 'submitted_at', 'completed_at',
    ];

    protected $casts = [
        'payload'      => 'array',
        'submitted_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function approvable(): MorphTo
    {
        return $this->morphTo();
    }

    public function steps(): HasMany
    {
        return $this->hasMany(ApprovalStep::class)->orderBy('level');
    }
}
