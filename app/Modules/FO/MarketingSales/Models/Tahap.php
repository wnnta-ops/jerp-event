<?php

namespace App\Modules\FO\MarketingSales\Models;

use App\Models\Concerns\TenantAwareModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Setup Unit — master Tahap (per Lokasi). Kode dipakai sebagai segmen kedua
 * No. Unit kanonik (Lokasi-Tahap-Blok-Nomer).
 */
class Tahap extends TenantAwareModel
{
    protected $table = 'fo_tahap';

    protected $fillable = [
        'tenant_id', 'lokasi_id', 'kode', 'nama', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function lokasi(): BelongsTo
    {
        return $this->belongsTo(Lokasi::class);
    }

    public function units(): HasMany
    {
        return $this->hasMany(Unit::class);
    }
}
