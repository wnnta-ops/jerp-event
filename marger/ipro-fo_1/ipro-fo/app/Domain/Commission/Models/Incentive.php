<?php

declare(strict_types=1);

namespace App\Domain\Commission\Models;

use App\Domain\Master\Customer\Models\Agent;
use App\Domain\Master\Sales\Models\SalesPerson;
use App\Domain\Sales\Booking\Models\Booking;
use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Pengajuan & realisasi insentif (BLT / Reward).
 */
class Incentive extends Model
{
    use HasTenant, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'incentive_no', 'type', 'beneficiary_type', 'sales_person_id', 'agent_id',
        'booking_id', 'scheme_id', 'description', 'amount', 'status',
        'submitted_by', 'submitted_at', 'approved_by', 'approved_at', 'realized_by', 'realized_at', 'reject_reason',
    ];

    protected $casts = [
        'amount'       => 'decimal:2',
        'submitted_at' => 'datetime',
        'approved_at'  => 'datetime',
        'realized_at'  => 'datetime',
    ];

    public const TYPES = ['blt' => 'BLT', 'reward' => 'Reward'];

    public const STATUSES = [
        'diajukan'    => 'Diajukan',
        'disetujui'   => 'Disetujui',
        'ditolak'     => 'Ditolak',
        'direalisasi' => 'Direalisasi',
    ];

    public function salesPerson(): BelongsTo
    {
        return $this->belongsTo(SalesPerson::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function scheme(): BelongsTo
    {
        return $this->belongsTo(IncentiveScheme::class, 'scheme_id');
    }

    public function getBeneficiaryNameAttribute(): ?string
    {
        return $this->beneficiary_type === 'agent' ? $this->agent?->name : $this->salesPerson?->name;
    }
}
