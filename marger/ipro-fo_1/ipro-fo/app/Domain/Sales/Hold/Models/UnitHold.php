<?php

declare(strict_types=1);

namespace App\Domain\Sales\Hold\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UnitHold extends Model
{
    use HasFactory, HasTenant;

    protected $fillable = [
        'tenant_id', 'unit_id', 'customer_id', 'sales_person_id',
        'held_at', 'expires_at', 'reason', 'status', 'created_by',
    ];

    protected $casts = [
        'held_at'    => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Master\Unit\Models\Unit::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Master\Customer\Models\Customer::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }
}
