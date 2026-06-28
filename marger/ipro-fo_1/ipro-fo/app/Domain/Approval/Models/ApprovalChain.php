<?php

declare(strict_types=1);

namespace App\Domain\Approval\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApprovalChain extends Model
{
    use HasFactory, HasTenant;

    protected $fillable = [
        'tenant_id', 'project_id', 'document_type', 'code', 'name',
        'match_conditions', 'is_active',
    ];

    protected $casts = [
        'match_conditions' => 'array',
        'is_active'        => 'boolean',
    ];

    public function steps(): HasMany
    {
        return $this->hasMany(ApprovalChainStep::class)->orderBy('level');
    }
}
