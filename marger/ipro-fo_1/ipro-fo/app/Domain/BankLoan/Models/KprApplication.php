<?php

declare(strict_types=1);

namespace App\Domain\BankLoan\Models;

use App\Domain\BankLoan\Enums\KprStatus;
use App\Support\Traits\HasAuditTrail;
use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class KprApplication extends Model
{
    use HasAuditTrail, HasFactory, HasTenant, SoftDeletes;

    protected $table = 'kpr_applications';

    protected $fillable = [
        'tenant_id', 'application_number', 'booking_id', 'customer_id',
        'bank_partner_id', 'bank_product_id', 'plafon_requested', 'plafon_approved',
        'tenor_month', 'submitted_at', 'reviewed_at', 'status', 'reject_reason', 'handled_by',
        'decision', 'decided_at', // keputusan SP3K: lanjut/batal (gerbang ke akad)
    ];

    protected $casts = [
        'status'           => KprStatus::class,
        'submitted_at'     => 'date',
        'reviewed_at'      => 'date',
        'decided_at'       => 'date',
        'plafon_requested' => 'decimal:2',
        'plafon_approved'  => 'decimal:2',
    ];

    public function booking(): BelongsTo  { return $this->belongsTo(\App\Domain\Sales\Booking\Models\Booking::class); }
    public function customer(): BelongsTo { return $this->belongsTo(\App\Domain\Master\Customer\Models\Customer::class); }
    public function bank(): BelongsTo     { return $this->belongsTo(BankPartner::class, 'bank_partner_id'); }
    public function documents(): HasMany  { return $this->hasMany(KprDocument::class); }
    public function sp3k(): HasOne        { return $this->hasOne(KprSp3k::class); }
    public function akad(): HasOne        { return $this->hasOne(KprAkad::class); }
    public function realizations(): HasMany { return $this->hasMany(KprRealization::class); }
}
