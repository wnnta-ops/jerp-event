<?php

declare(strict_types=1);

namespace App\Domain\Finance\FakturPajak\Models;

use App\Domain\Master\Customer\Models\Customer;
use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FakturPajak extends Model
{
    use HasTenant;

    protected $fillable = [
        'tenant_id', 'fp_number', 'customer_id', 'booking_id', 'issued_at',
        'dpp', 'ppn', 'ppnbm', 'total', 'status', 'djp_response',
    ];

    protected $casts = [
        'issued_at'    => 'date',
        'dpp'          => 'decimal:2',
        'ppn'          => 'decimal:2',
        'ppnbm'        => 'decimal:2',
        'total'        => 'decimal:2',
        'djp_response' => 'array',
    ];

    public function items(): HasMany { return $this->hasMany(FakturPajakItem::class); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class, 'customer_id'); }
    public function booking(): BelongsTo { return $this->belongsTo(\App\Domain\Sales\Booking\Models\Booking::class); }
}
