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
        'status', 'requested_by', 'approved_by', 'approved_at',
        'catatan_approver',
    ];

    protected $casts = [
        'tgl_approval' => 'date',
        'approved_at' => 'datetime',
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

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
