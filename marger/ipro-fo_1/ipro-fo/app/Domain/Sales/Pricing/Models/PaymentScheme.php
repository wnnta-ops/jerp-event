<?php

declare(strict_types=1);

namespace App\Domain\Sales\Pricing\Models;

use App\Domain\Foundation\Tenant\Models\Project;
use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PaymentScheme extends Model
{
    use HasFactory, HasTenant, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'project_id', 'code', 'name', 'type',
        'down_payment_pct', 'installment_months', 'discount_pct', 'is_active',
        // Rumus Global
        'present_value', 'tiered_discounts', 'booking_fee', 'bf_treatment',
        // Rumus Nilai
        'dp_count', 'dp_basis', 'dp_value', 'inst_count', 'inst_basis', 'inst_value',
        // Rumus Jadwal
        'schedule_first', 'schedule_interval', 'schedule_unit', 'notes',
    ];

    protected $casts = [
        'is_active'           => 'boolean',
        'present_value'       => 'boolean',
        'tiered_discounts'    => 'array',
        'down_payment_pct'    => 'decimal:2',
        'discount_pct'        => 'decimal:2',
        'booking_fee'         => 'decimal:2',
        'dp_value'            => 'decimal:2',
        'inst_value'          => 'decimal:2',
    ];

    /** Booking-fee treatment options (eProperty: "Booking Fee dipotong di"). */
    public const BF_TREATMENTS = [
        'dp1'              => 'Dipotong di DP-1',
        'angsuran1'        => 'Dipotong di Angsuran-1',
        'dp_disebar'       => 'DP Disebar',
        'angsuran_disebar' => 'Angsuran Disebar',
        'tidak'            => 'Tidak Disebar',
    ];

    public const TYPES = [
        'cash_keras' => 'Cash Keras',
        'cicilan'    => 'Cash Bertahap',
        'kpr'        => 'KPR',
        'kpa'        => 'KPA',
        'sewa_beli'  => 'Sewa Beli',
        'mixed'      => 'Campuran',
    ];

    public function terms(): HasMany
    {
        return $this->hasMany(PaymentSchemeTerm::class)->orderBy('term_no');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
