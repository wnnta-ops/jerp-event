<?php

declare(strict_types=1);

namespace App\Domain\Approval\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApprovalStep extends Model
{
    // approver_type/value/label = snapshot chain step saat diajukan (perubahan chain tidak menyusul).
    protected $fillable = ['approval_id', 'level', 'approver_id', 'approver_type', 'approver_value', 'label', 'status', 'notes', 'acted_at'];

    protected $casts = ['acted_at' => 'datetime'];

    public function actions(): HasMany
    {
        return $this->hasMany(ApprovalAction::class);
    }
}
