<?php

namespace App\Modules\FO\Approval\Models;

use App\Models\Concerns\TenantAwareModel;
use App\Models\User;
use App\Services\Numerator\NumeratorService;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Approval extends TenantAwareModel
{
    protected $table = 'fo_approvals';

    protected static function booted(): void
    {
        parent::booted();
        static::creating(function (self $m) {
            if (empty($m->no_approval)) {
                $m->no_approval = app(NumeratorService::class)->next('APPROVAL');
            }
            // Default aman: status & level tak bergantung pada input form.
            $m->status ??= 'DRAFT';
            $m->current_level ??= 1;
            $m->requested_by ??= auth()->id();
        });

        // Pengajuan BLT/Komisi ditolak → batch kembali DRAFT (ke menu Pengajuan).
        static::updated(function (self $m) {
            if (! $m->wasChanged('status') || $m->status !== 'REJECTED') {
                return;
            }
            $no = (string) (((array) $m->detail_baru)['no_pengajuan'] ?? '');
            if ($no === '') {
                return;
            }
            if ($m->jenis === 'BLT') {
                \App\Modules\FO\Komisi\Models\Blt::query()
                    ->where('no_pengajuan', $no)->where('status', 'PENDING')
                    ->update(['status' => 'DRAFT', 'no_pengajuan' => null]);
            } elseif ($m->jenis === 'KOMISI') {
                \App\Modules\FO\Komisi\Models\Komisi::query()
                    ->where('no_pengajuan', $no)->where('status', 'PENDING')
                    ->update(['status' => 'DRAFT', 'no_pengajuan' => null]);
            }
        });
    }

    protected $fillable = [
        'tenant_id', 'no_approval', 'tgl_approval', 'jenis',
        'subject_type', 'subject_id',
        'alasan', 'detail_lama', 'detail_baru', 'amount_dampak',
        'status', 'current_level',
        'level1_by', 'level1_at', 'level1_note',
        'requested_by', 'approved_by', 'approved_at',
        'rejected_by', 'rejected_at',
        'catatan_approver', 'executed_at', 'executed_result',
    ];

    protected $casts = [
        'tgl_approval' => 'date',
        'level1_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
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
