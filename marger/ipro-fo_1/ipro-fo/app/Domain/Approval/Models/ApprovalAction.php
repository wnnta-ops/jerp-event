<?php

declare(strict_types=1);

namespace App\Domain\Approval\Models;

use Illuminate\Database\Eloquent\Model;

class ApprovalAction extends Model
{
    protected $fillable = ['approval_step_id', 'action', 'actor_id', 'notes', 'acted_at'];

    protected $casts = ['acted_at' => 'datetime'];
}
