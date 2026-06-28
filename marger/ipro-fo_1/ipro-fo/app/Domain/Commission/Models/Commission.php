<?php

declare(strict_types=1);

namespace App\Domain\Commission\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Commission extends Model
{
    use HasFactory, HasTenant;

    protected $fillable = ['tenant_id', 'booking_id', 'commission_scheme_id', 'base_amount', 'status'];
    protected $casts    = ['base_amount' => 'decimal:2'];

    public function booking(): BelongsTo { return $this->belongsTo(\App\Domain\Sales\Booking\Models\Booking::class); }
    public function lines(): HasMany     { return $this->hasMany(CommissionLine::class); }
}
