<?php

declare(strict_types=1);

namespace App\Domain\Sales\Nup\Models;

use App\Support\Traits\HasAuditTrail;
use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class NupRegistration extends Model
{
    use HasAuditTrail, HasFactory, HasTenant, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'nup_scheme_id', 'customer_id', 'nup_number', 'queue_no',
        'registered_at', 'sales_person_id', 'agent_id', 'status',
        'verification_status', 'verified_at', 'verified_by', 'verification_note',
        'form_data', 'payment_status', 'payment_provider', 'payment_ref', 'payment_url', 'payment_external_id',
    ];

    protected $casts = [
        'registered_at' => 'date',
        'verified_at'   => 'datetime',
        'form_data'     => 'array',
    ];

    public function scheme(): BelongsTo  { return $this->belongsTo(NupScheme::class, 'nup_scheme_id'); }
    public function customer(): BelongsTo{ return $this->belongsTo(\App\Domain\Master\Customer\Models\Customer::class); }
    public function payments(): HasMany  { return $this->hasMany(NupPayment::class); }
    public function verifier(): BelongsTo{ return $this->belongsTo(\App\Models\User::class, 'verified_by'); }
}
