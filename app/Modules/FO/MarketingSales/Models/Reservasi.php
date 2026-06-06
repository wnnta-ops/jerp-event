<?php

namespace App\Modules\FO\MarketingSales\Models;

use App\Models\Concerns\TenantAwareModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Reservasi extends TenantAwareModel
{
    protected $table = 'fo_reservasi';

    protected $fillable = [
        'tenant_id',
        'no_reservasi',
        'customer_id',
        'unit_id',
        'tgl_reservasi',
        'tgl_kadaluarsa',
        'harga_jual',
        'booking_fee',
        'status',
        'keterangan',
    ];

    protected $casts = [
        'tgl_reservasi' => 'date',
        'tgl_kadaluarsa' => 'date',
        'harga_jual' => 'decimal:2',
        'booking_fee' => 'decimal:2',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function kontrak(): HasOne
    {
        return $this->hasOne(Kontrak::class);
    }
}
