<?php

namespace App\Modules\FO\MarketingSales\Models;

use App\Models\Concerns\TenantAwareModel;
use App\Models\Project;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Setup Unit — master Lokasi (per Project). Kode dipakai sebagai segmen
 * pertama No. Unit kanonik (Lokasi-Tahap-Blok-Nomer).
 */
class Lokasi extends TenantAwareModel
{
    protected $table = 'fo_lokasi';

    protected $fillable = [
        'tenant_id', 'project_id', 'kode', 'nama', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function tahap(): HasMany
    {
        return $this->hasMany(Tahap::class);
    }

    public function units(): HasMany
    {
        return $this->hasMany(Unit::class);
    }
}
