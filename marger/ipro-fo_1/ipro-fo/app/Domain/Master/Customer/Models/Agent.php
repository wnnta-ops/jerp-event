<?php

declare(strict_types=1);

namespace App\Domain\Master\Customer\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Agent extends Model
{
    use HasFactory, HasTenant, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'code', 'name', 'agent_type_id', 'phone', 'email',
        'npwp', 'bank_name', 'bank_account_no', 'bank_account_name',
        'parent_agent_id', 'is_active',
    ];

    protected $casts = [
        'is_active'        => 'boolean',
        'npwp'             => \App\Support\Casts\EncryptedString::class,
        'bank_account_no'  => \App\Support\Casts\EncryptedString::class,
    ];

    public function agentType(): BelongsTo
    {
        return $this->belongsTo(AgentType::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_agent_id');
    }
}
