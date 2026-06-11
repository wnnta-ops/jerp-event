<?php

namespace App\Modules\FO\Approval\Models;

use App\Models\Concerns\TenantAwareModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Approval extends TenantAwareModel
{
    protected $table = 'fo_approvals';

    protected $fillable = [
        'tenant_id', 'no_approval', 'tgl_approval', 'jenis',
        'subject_type', 'subject_id',
        'alasan', 'detail_lama', 'detail_baru', 'amount_dampak',
        'status', 'current_level',
        'level1_by', 'level1_at', 'level1_note',
        'requested_by', 'approved_by', 'approved_at',
        'catatan_approver', 'executed_at', 'executed_result',
    ];

    protected $casts = [
        'tgl_approval' => 'date',
        'level1_at' => 'datetime',
        'approved_at' => 'datetime',
        'executed_at' => 'datetime',
        'amount_dampak' => 'decimal:2',
        'detail_lama' => 'array',
        'detail_baru' => 'array',
    ];

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function level1Approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'level1_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isAwaitingLevel1(): bool
    {
        return $this->status === 'PENDING' && (int) $this->current_level === 1;
    }

    public function isAwaitingLevel2(): bool
    {
        return $this->status === 'PENDING' && (int) $this->current_level === 2;
    }
}
