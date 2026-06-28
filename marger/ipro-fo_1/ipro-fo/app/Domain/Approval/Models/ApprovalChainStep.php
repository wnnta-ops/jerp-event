<?php

declare(strict_types=1);

namespace App\Domain\Approval\Models;

use Illuminate\Database\Eloquent\Model;

class ApprovalChainStep extends Model
{
    protected $fillable = [
        'approval_chain_id', 'level', 'approver_type', 'approver_value', 'threshold', 'is_final',
    ];

    protected $casts = [
        'threshold' => 'array',
        'is_final'  => 'boolean',
    ];
}
