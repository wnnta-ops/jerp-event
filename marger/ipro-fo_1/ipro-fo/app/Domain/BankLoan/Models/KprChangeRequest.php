<?php

declare(strict_types=1);

namespace App\Domain\BankLoan\Models;

use App\Models\User;
use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pengajuan perubahan data Proses KPR (dan catatan perubahan yang dilakukan langsung).
 * status: pending | approved | rejected | cancelled | direct.
 */
class KprChangeRequest extends Model
{
    use HasTenant;

    protected $fillable = [
        'tenant_id', 'kpr_application_id', 'stage', 'action', 'target_id', 'old_data', 'new_data', 'reason',
        'status', 'requested_by', 'decided_by', 'decided_at', 'decision_note',
    ];

    protected $casts = [
        'old_data'   => 'array',
        'new_data'   => 'array',
        'decided_at' => 'datetime',
    ];

    public function kpr(): BelongsTo
    {
        return $this->belongsTo(KprApplication::class, 'kpr_application_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
