<?php

declare(strict_types=1);

namespace App\Domain\Crm\Models;

use App\Domain\Crm\Enums\LeadStage;
use App\Domain\Master\Customer\Models\Agent;
use App\Domain\Master\Customer\Models\Customer;
use App\Domain\Master\Customer\Models\DataSource;
use App\Domain\Master\Unit\Models\Unit;
use App\Models\User;
use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CrmLead extends Model
{
    use HasTenant, SoftDeletes;

    protected $table = 'crm_leads';
    protected $fillable = [
        'tenant_id', 'lead_number', 'name', 'phone', 'email', 'data_source_id', 'agent_id',
        'assigned_to', 'unit_id', 'interest', 'stage', 'est_value', 'customer_id', 'booking_id',
        'lost_reason', 'last_contact_at', 'next_followup_at', 'notes', 'created_by',
    ];
    protected $casts = [
        'stage' => LeadStage::class,
        'est_value' => 'decimal:2',
        'last_contact_at' => 'date', 'next_followup_at' => 'date',
    ];

    public function dataSource(): BelongsTo { return $this->belongsTo(DataSource::class, 'data_source_id'); }
    public function agent(): BelongsTo { return $this->belongsTo(Agent::class, 'agent_id'); }
    public function assignedTo(): BelongsTo { return $this->belongsTo(User::class, 'assigned_to'); }
    public function unit(): BelongsTo { return $this->belongsTo(Unit::class, 'unit_id'); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class, 'customer_id'); }
    public function activities(): MorphMany { return $this->morphMany(ActivityLog::class, 'subject'); }
}
